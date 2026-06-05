<?php

declare(strict_types=1);

namespace Infouno\SaaS\Core;

/**
 * Crea o actualiza las tablas custom del SaaS.
 * Usa dbDelta() — idempotente: añade columnas/índices pero NUNCA elimina.
 * NUNCA ejecutar DROP TABLE aquí — ver ia/guardrails/code-quality.md.
 *
 * La versión activa se controla con self::DB_VERSION.
 * El arranque se delega desde Plugin::maybeMigrate().
 *
 * Versiones:
 *   v1 — Esquema inicial.
 *   v2 — tokens_input/tokens_output, quota_reset_at, composite indexes.
 *   v3 — Cuota basada en tokens. quota_limit DEFAULT 50000.
 *   v4 — Tabla de consentimientos (Ley 25.326).
 *         Soft delete en conversations y messages (deleted_at).
 *         Índice compuesto tenant_bot_created en conversations.
 *   v5 — Columna scope en consents ('chat' | 'lead_capture'). Índice scope.
 *         Tabla de leads capturados (Lead Engine). Datos PII con consentimiento previo.
 *         Tabla de consentimientos granulares por campo PII (lead_consents). Ley 25.326.
 *   v6 — Status 'interested' en leads (pipeline más granular: new→contacted→interested→converted|lost).
 *         Columna page_url en leads (URL de la página donde ocurrió la conversación).
 *         Columna quick_replies en bots settings (botones de respuesta rápida del widget).
 *         Columna whatsapp_number en bots settings (número para escalación directa al negocio).
 *   v7 — Temperatura comercial de lead: columna temperature ENUM('cold','warm','hot','ready').
 *         Señales de intención estructuradas: columna intent_signals JSON (BANT: budget, authority,
 *         timeline, industry, location, company).
 *         Knowledge Builder: columna wizard_data JSON en bots para almacenar datos del wizard.
 *   v8 — Opportunity Engine: tabla wp_infouno_opportunities (pipeline stages, estimated_value,
 *         timestamps won/lost, assigned_to). Tabla wp_infouno_automation_logs (registro de
 *         automatizaciones por oportunidad: email, webhook, WhatsApp).
 */
final class Migrator {

    const DB_VERSION        = '9';
    const DB_VERSION_OPTION = 'infouno_db_version';

    public function run(): void {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $current = get_option( self::DB_VERSION_OPTION, '0' );

        // Upgrades incrementales — solo en instalaciones previas (current >= '1').
        // Con current = '0' las tablas no existen aún; los create* las crean completas.
        if ( version_compare( $current, '1', '>=' ) && version_compare( $current, '5', '<' ) ) {
            $this->migrateTo5( $wpdb );
        }

        if ( version_compare( $current, '1', '>=' ) && version_compare( $current, '6', '<' ) ) {
            $this->migrateTo6( $wpdb );
        }

        if ( version_compare( $current, '1', '>=' ) && version_compare( $current, '7', '<' ) ) {
            $this->migrateTo7( $wpdb );
        }

        if ( version_compare( $current, '1', '>=' ) && version_compare( $current, '8', '<' ) ) {
            $this->migrateTo8( $wpdb, $charset );
        }

        if ( version_compare( $current, '1', '>=' ) && version_compare( $current, '9', '<' ) ) {
            $this->migrateTo9( $wpdb );
        }

        // Fresh install: crea todas las tablas que aún no existan (dbDelta es idempotente).
        $this->createTenantsTable( $wpdb, $charset );
        $this->createBotsTable( $wpdb, $charset );
        $this->createConversationsTable( $wpdb, $charset );
        $this->createMessagesTable( $wpdb, $charset );
        $this->createConsentsTable( $wpdb, $charset );
        $this->createLeadsTable( $wpdb, $charset );
        $this->createLeadConsentsTable( $wpdb, $charset );
        $this->createOpportunitiesTable( $wpdb, $charset );
        $this->createAutomationLogsTable( $wpdb, $charset );
        $this->createChannelsTable( $wpdb, $charset );
        $this->createChannelEventsTable( $wpdb, $charset );

        $this->migrateQuotasToTokens( $wpdb );

        update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
    }

    /**
     * Upgrade path v5 → v6.
     *
     * 1. Agrega 'interested' al ENUM status de wp_infouno_leads — pipeline más granular.
     * 2. Agrega columna page_url a wp_infouno_leads — tracking de origen de conversación.
     *
     * El campo quick_replies y whatsapp_number son parte de la columna settings (JSON)
     * de wp_infouno_bots — no requieren ALTER TABLE; se manejan como claves del JSON.
     */
    private function migrateTo6( \wpdb $wpdb ): void {
        $tableLeads = $wpdb->prefix . 'infouno_leads';

        // Modifica el ENUM de status para incluir 'interested' (pipeline Fase 2).
        // MODIFY COLUMN es idempotente en MySQL si el tipo final ya es el deseado.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query(
            "ALTER TABLE `{$tableLeads}`
             MODIFY COLUMN status
             ENUM('new','contacted','interested','converted','lost')
             NOT NULL DEFAULT 'new'"
        );

        // Agrega page_url si no existe — registra la URL de la página donde ocurrió la sesión.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $colExists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = %s
                   AND COLUMN_NAME  = 'page_url'",
                $tableLeads
            )
        );

        if ( ! $colExists ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->query( "ALTER TABLE `{$tableLeads}` ADD COLUMN page_url VARCHAR(500) NULL AFTER source" );
        }
    }

    /**
     * Upgrade path v6 → v7.
     *
     * 1. temperature en wp_infouno_leads — temperatura comercial derivada del score y señales BANT.
     * 2. intent_signals en wp_infouno_leads — JSON con señales estructuradas (budget, authority,
     *    timeline, industry, location, company).
     * 3. wizard_data en wp_infouno_bots — JSON con los datos ingresados en el Knowledge Builder.
     */
    private function migrateTo7( \wpdb $wpdb ): void {
        $tableLeads = $wpdb->prefix . 'infouno_leads';
        $tableBots  = $wpdb->prefix . 'infouno_bots';

        // Temperatura comercial — columna ENUM con valor default 'cold'.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $colExists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = %s
                   AND COLUMN_NAME  = 'temperature'",
                $tableLeads
            )
        );

        if ( ! $colExists ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->query(
                "ALTER TABLE `{$tableLeads}`
                 ADD COLUMN temperature ENUM('cold','warm','hot','ready') NOT NULL DEFAULT 'cold'
                 AFTER score"
            );
        }

        // Señales BANT estructuradas — JSON nullable.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $colExists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = %s
                   AND COLUMN_NAME  = 'intent_signals'",
                $tableLeads
            )
        );

        if ( ! $colExists ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->query(
                "ALTER TABLE `{$tableLeads}`
                 ADD COLUMN intent_signals JSON NULL
                 AFTER temperature"
            );
        }

        // wizard_data en bots — JSON nullable para el Knowledge Builder.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $colExists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = %s
                   AND COLUMN_NAME  = 'wizard_data'",
                $tableBots
            )
        );

        if ( ! $colExists ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->query(
                "ALTER TABLE `{$tableBots}`
                 ADD COLUMN wizard_data JSON NULL
                 AFTER settings"
            );
        }
    }

    /**
     * Upgrade path desde v4 → v5.
     *
     * En instalaciones existentes dbDelta no añade columnas nuevas a tablas ya creadas,
     * por lo que el ALTER TABLE es necesario para el campo scope. Las tablas leads y
     * lead_consents son nuevas en v5, pero se crean también vía los métodos create*
     * (idempotentes), así que aquí sólo se aplica el ALTER TABLE de consents.
     */
    private function migrateTo5( \wpdb $wpdb ): void {
        $tableConsents = $wpdb->prefix . 'infouno_consents';

        // Añade scope solo si la columna no existe. Compatible MySQL 5.7+ y MariaDB 10.3+.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $colExists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = %s
                   AND COLUMN_NAME  = 'scope'",
                $tableConsents
            )
        );

        if ( ! $colExists ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->query( "ALTER TABLE `{$tableConsents}` ADD COLUMN scope VARCHAR(50) NOT NULL DEFAULT 'chat' AFTER consent_version" );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->query( "ALTER TABLE `{$tableConsents}` ADD INDEX idx_scope (scope)" );
        }
    }

    private function createTenantsTable( \wpdb $wpdb, string $charset ): void {
        $table = $wpdb->prefix . 'infouno_tenants';
        $sql   = "CREATE TABLE {$table} (
            id             INT UNSIGNED    NOT NULL AUTO_INCREMENT,
            uuid           VARCHAR(36)     NOT NULL,
            user_id        BIGINT UNSIGNED NOT NULL,
            status         VARCHAR(20)     NOT NULL DEFAULT 'active',
            plan           VARCHAR(50)     NOT NULL DEFAULT 'free',
            quota_limit    INT UNSIGNED    NOT NULL DEFAULT 50000,
            quota_used     INT UNSIGNED    NOT NULL DEFAULT 0,
            quota_reset_at DATETIME        NULL,
            created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uuid    (uuid),
            KEY        user_id (user_id),
            KEY        status  (status)
        ) {$charset};";

        dbDelta( $sql );
    }

    private function createBotsTable( \wpdb $wpdb, string $charset ): void {
        $table = $wpdb->prefix . 'infouno_bots';
        $sql   = "CREATE TABLE {$table} (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            tenant_id       INT UNSIGNED NOT NULL,
            bot_name        VARCHAR(100) NOT NULL,
            public_token    VARCHAR(64)  NOT NULL,
            system_prompt   TEXT         NOT NULL,
            settings        JSON         NULL,
            wizard_data     JSON         NULL,
            llm_provider    VARCHAR(50)  NOT NULL DEFAULT 'anthropic',
            llm_model       VARCHAR(100) NOT NULL DEFAULT 'claude-haiku-4-5-20251001',
            allowed_origins TEXT         NULL,
            is_active       TINYINT(1)   NOT NULL DEFAULT 1,
            created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY public_token (public_token),
            KEY        tenant_id   (tenant_id),
            KEY        is_active   (is_active)
        ) {$charset};";

        dbDelta( $sql );
    }

    private function createConversationsTable( \wpdb $wpdb, string $charset ): void {
        $table = $wpdb->prefix . 'infouno_conversations';
        $sql   = "CREATE TABLE {$table} (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tenant_id  INT UNSIGNED    NOT NULL,
            bot_id     INT UNSIGNED    NOT NULL,
            session_id VARCHAR(64)     NOT NULL,
            channel       VARCHAR(20)  NOT NULL DEFAULT 'web',
            external_user VARCHAR(191) NULL,
            metadata   JSON            NULL,
            deleted_at DATETIME        NULL,
            created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY tenant_bot_session (tenant_id, bot_id, session_id),
            KEY        tenant_id         (tenant_id),
            KEY        bot_id            (bot_id),
            KEY        tenant_bot_created (tenant_id, bot_id, created_at),
            KEY        deleted_at        (deleted_at)
        ) {$charset};";

        dbDelta( $sql );
    }

    private function createMessagesTable( \wpdb $wpdb, string $charset ): void {
        $table = $wpdb->prefix . 'infouno_messages';
        $sql   = "CREATE TABLE {$table} (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            conversation_id BIGINT UNSIGNED NOT NULL,
            role            ENUM('system','user','assistant') NOT NULL,
            content         TEXT            NOT NULL,
            tokens_input    INT UNSIGNED    NOT NULL DEFAULT 0,
            tokens_output   INT UNSIGNED    NOT NULL DEFAULT 0,
            tokens_used     INT UNSIGNED    NOT NULL DEFAULT 0,
            deleted_at      DATETIME        NULL,
            expires_at      DATETIME        NULL,
            created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY        conv_created (conversation_id, created_at),
            KEY        expires_at   (expires_at),
            KEY        deleted_at   (deleted_at)
        ) {$charset};";

        dbDelta( $sql );
    }

    /**
     * Tabla de logs de consentimiento — Ley 25.326 Argentina.
     *
     * Registra server-side cada vez que un usuario acepta el aviso legal.
     * Campos: quién (session_hash), cuándo (accepted_at), qué versión del texto
     * y desde qué bot. Sin datos personales identificables directamente.
     */
    private function createConsentsTable( \wpdb $wpdb, string $charset ): void {
        $table = $wpdb->prefix . 'infouno_consents';
        $sql   = "CREATE TABLE {$table} (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            bot_id          INT UNSIGNED    NOT NULL,
            tenant_id       INT UNSIGNED    NOT NULL,
            session_hash    VARCHAR(64)     NOT NULL,
            consent_version VARCHAR(10)     NOT NULL DEFAULT '1.0',
            scope           VARCHAR(50)     NOT NULL DEFAULT 'chat',
            channel         VARCHAR(20)     NOT NULL DEFAULT 'web',
            ip_hash         VARCHAR(64)     NOT NULL,
            user_agent_hash VARCHAR(64)     NOT NULL,
            accepted_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY tenant_id    (tenant_id),
            KEY bot_id       (bot_id),
            KEY session_hash (session_hash),
            KEY accepted_at  (accepted_at),
            KEY scope        (scope)
        ) {$charset};";

        dbDelta( $sql );
    }

    /**
     * Tabla de leads capturados por el Lead Engine.
     *
     * Almacena datos PII (name, phone, email) únicamente bajo consentimiento
     * previo registrado en wp_infouno_consents (scope = 'lead_capture').
     * Sin FK declaradas — integridad garantizada por capa de aplicación.
     */
    private function createLeadsTable( \wpdb $wpdb, string $charset ): void {
        $table = $wpdb->prefix . 'infouno_leads';
        $sql   = "CREATE TABLE {$table} (
            id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
            tenant_id       INT UNSIGNED     NOT NULL,
            bot_id          INT UNSIGNED     NOT NULL,
            conversation_id BIGINT UNSIGNED  NULL,
            session_hash    VARCHAR(64)      NOT NULL,
            name            VARCHAR(100)     NULL,
            phone           VARCHAR(50)      NULL,
            email           VARCHAR(255)     NULL,
            interest        TEXT             NULL,
            score           TINYINT UNSIGNED NOT NULL DEFAULT 0,
            temperature     ENUM('cold','warm','hot','ready') NOT NULL DEFAULT 'cold',
            intent_signals  JSON             NULL,
            source          VARCHAR(50)      NOT NULL DEFAULT 'chat',
            page_url        VARCHAR(500)     NULL,
            status          ENUM('new','contacted','interested','converted','lost') NOT NULL DEFAULT 'new',
            notes           TEXT             NULL,
            assigned_to     BIGINT UNSIGNED  NULL,
            contacted_at    DATETIME         NULL,
            converted_at    DATETIME         NULL,
            created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY tenant_id    (tenant_id),
            KEY bot_id       (bot_id),
            KEY session_hash (session_hash),
            KEY status_score (status, score),
            KEY created_at   (created_at)
        ) {$charset};";

        dbDelta( $sql );
    }

    /**
     * Tabla de consentimientos granulares para captura de datos PII — Ley 25.326.
     *
     * Registra qué campos específicos consintió capturar el usuario (nombre,
     * teléfono, email). Evidencia independiente de wp_infouno_consents, que
     * solo registra el consentimiento general de uso del chat.
     */
    private function createLeadConsentsTable( \wpdb $wpdb, string $charset ): void {
        $table = $wpdb->prefix . 'infouno_lead_consents';
        $sql   = "CREATE TABLE {$table} (
            id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tenant_id         INT UNSIGNED    NOT NULL,
            bot_id            INT UNSIGNED    NOT NULL,
            session_hash      VARCHAR(64)     NOT NULL,
            can_capture_name  TINYINT(1)      NOT NULL DEFAULT 0,
            can_capture_phone TINYINT(1)      NOT NULL DEFAULT 0,
            can_capture_email TINYINT(1)      NOT NULL DEFAULT 0,
            consent_version   VARCHAR(10)     NOT NULL DEFAULT '1.0',
            ip_hash           VARCHAR(64)     NOT NULL,
            user_agent_hash   VARCHAR(64)     NOT NULL,
            accepted_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY session_hash (session_hash),
            KEY tenant_bot   (tenant_id, bot_id)
        ) {$charset};";

        dbDelta( $sql );
    }

    /**
     * Upgrade path v7 → v8.
     *
     * Crea las tablas del Opportunity Engine si no existen.
     * dbDelta() es idempotente — safe re-run.
     */
    private function migrateTo8( \wpdb $wpdb, string $charset ): void {
        $this->createOpportunitiesTable( $wpdb, $charset );
        $this->createAutomationLogsTable( $wpdb, $charset );
    }

    /**
     * Tabla del Opportunity Engine — pipeline comercial de oportunidades.
     *
     * Una oportunidad activa por lead (la segunda solo se crea cuando la anterior
     * terminó en 'won' o 'lost'). tenant_id en toda query — aislamiento multitenant.
     */
    private function createOpportunitiesTable( \wpdb $wpdb, string $charset ): void {
        $table = $wpdb->prefix . 'infouno_opportunities';
        $sql   = "CREATE TABLE {$table} (
            id               BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
            tenant_id        INT UNSIGNED     NOT NULL,
            lead_id          BIGINT UNSIGNED  NOT NULL,
            bot_id           INT UNSIGNED     NOT NULL,
            stage            ENUM('new','contacted','interested','quoted','won','lost') NOT NULL DEFAULT 'new',
            estimated_value  DECIMAL(12,2)    NULL,
            currency         VARCHAR(3)       NOT NULL DEFAULT 'ARS',
            assigned_to      BIGINT UNSIGNED  NULL,
            notes            TEXT             NULL,
            lost_reason      VARCHAR(200)     NULL,
            stage_changed_at DATETIME         NULL,
            won_at           DATETIME         NULL,
            lost_at          DATETIME         NULL,
            created_at       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY tenant_stage (tenant_id, stage),
            KEY tenant_lead  (tenant_id, lead_id),
            KEY bot_id       (bot_id),
            KEY created_at   (created_at)
        ) {$charset};";

        dbDelta( $sql );
    }

    /**
     * Tabla de logs de automatizaciones — audit trail de acciones disparadas por oportunidades.
     *
     * Registra cada intento de email, webhook, WhatsApp o recordatorio.
     * Idempotencia garantizada por la lógica de OpportunityService (no aquí).
     */
    private function createAutomationLogsTable( \wpdb $wpdb, string $charset ): void {
        $table = $wpdb->prefix . 'infouno_automation_logs';
        $sql   = "CREATE TABLE {$table} (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tenant_id       INT UNSIGNED    NOT NULL,
            opportunity_id  BIGINT UNSIGNED NULL,
            lead_id         BIGINT UNSIGNED NULL,
            action_type     VARCHAR(50)     NOT NULL,
            status          VARCHAR(20)     NOT NULL DEFAULT 'ok',
            metadata        JSON            NULL,
            created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY tenant_id      (tenant_id),
            KEY opportunity_id (opportunity_id),
            KEY lead_id        (lead_id),
            KEY action_type    (action_type),
            KEY status         (status)
        ) {$charset};";

        dbDelta( $sql );
    }

    /**
     * v3: Actualiza quota_limit de tenants existentes al valor token del plan.
     * Constantes inline — el Migrator no debe depender de clases de negocio.
     * Idempotente: solo actualiza filas con quota_limit < nuevo valor.
     */
    private function migrateQuotasToTokens( \wpdb $wpdb ): void {
        $table = $wpdb->prefix . 'infouno_tenants';

        $planQuotas = [
            'free'    =>    50_000,
            'trial'   =>   200_000,
            'premium' => 2_000_000,
            'agency'  => 20_000_000,
        ];

        foreach ( $planQuotas as $plan => $tokenLimit ) {
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE `{$table}` SET quota_limit = %d WHERE plan = %s AND quota_limit < %d",
                    $tokenLimit,
                    $plan,
                    $tokenLimit
                )
            );
        }
    }

    /**
     * Upgrade path v8 → v9 — Canales Sociales (Fase 1).
     *
     * 1. Crea wp_infouno_channels y wp_infouno_channel_events (idempotente vía dbDelta).
     * 2. Agrega channel + external_user a wp_infouno_conversations.
     * 3. Agrega channel a wp_infouno_consents.
     */
    private function migrateTo9( \wpdb $wpdb ): void {
        $charset = $wpdb->get_charset_collate();
        $this->createChannelsTable( $wpdb, $charset );
        $this->createChannelEventsTable( $wpdb, $charset );

        $tableConv = $wpdb->prefix . 'infouno_conversations';
        $this->addColumnIfMissing(
            $wpdb,
            $tableConv,
            'channel',
            "ADD COLUMN channel VARCHAR(20) NOT NULL DEFAULT 'web' AFTER session_id"
        );
        $this->addColumnIfMissing(
            $wpdb,
            $tableConv,
            'external_user',
            'ADD COLUMN external_user VARCHAR(191) NULL AFTER channel'
        );

        $tableConsents = $wpdb->prefix . 'infouno_consents';
        $this->addColumnIfMissing(
            $wpdb,
            $tableConsents,
            'channel',
            "ADD COLUMN channel VARCHAR(20) NOT NULL DEFAULT 'web' AFTER scope"
        );
    }

    /** Agrega una columna solo si no existe — idempotente. MySQL 5.7+ / MariaDB 10.3+. */
    private function addColumnIfMissing( \wpdb $wpdb, string $table, string $column, string $alterClause ): void {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                $table,
                $column
            )
        );

        if ( ! $exists ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
            $wpdb->query( "ALTER TABLE `{$table}` {$alterClause}" );
        }
    }

    /**
     * Conexión de canal por tenant/bot. Las credenciales van CIFRADAS (CredentialVault).
     * routing_key resuelve un webhook entrante → tenant + bot.
     */
    private function createChannelsTable( \wpdb $wpdb, string $charset ): void {
        $table = $wpdb->prefix . 'infouno_channels';
        $sql   = "CREATE TABLE {$table} (
            id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
            tenant_id      INT UNSIGNED NOT NULL,
            bot_id         INT UNSIGNED NOT NULL,
            channel_type   VARCHAR(20)  NOT NULL,
            routing_key    VARCHAR(191) NOT NULL,
            credentials    TEXT         NULL,
            webhook_secret VARCHAR(64)  NULL,
            status         VARCHAR(20)  NOT NULL DEFAULT 'active',
            display_name   VARCHAR(100) NULL,
            created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY routing_key (routing_key),
            KEY tenant_id    (tenant_id),
            KEY bot_id       (bot_id),
            KEY channel_type (channel_type)
        ) {$charset};";

        dbDelta( $sql );
    }

    /**
     * Idempotencia de webhooks: INSERT IGNORE sobre (channel_id, external_msg_id)
     * garantiza que cada mensaje entrante se procesa una sola vez.
     *
     * La UNIQUE es por channel_id (no por channel_type) porque external_msg_id
     * suele ser secuencial por canal (ej. update_id de Telegram) y colisionaría
     * entre tenants distintos, descartando mensajes legítimos cross-tenant.
     * channel_type se conserva solo por legibilidad.
     */
    private function createChannelEventsTable( \wpdb $wpdb, string $charset ): void {
        $table = $wpdb->prefix . 'infouno_channel_events';
        $sql   = "CREATE TABLE {$table} (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            channel_id      INT UNSIGNED    NOT NULL,
            channel_type    VARCHAR(20)     NOT NULL,
            external_msg_id VARCHAR(191)    NOT NULL,
            received_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY chan_msg (channel_id, external_msg_id),
            KEY received_at (received_at)
        ) {$charset};";

        dbDelta( $sql );
    }
}
