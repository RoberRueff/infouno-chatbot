# Template: Nueva Migración de Base de Datos

> Usar este template para planificar una migración antes de escribir código en `Migrator.php`.
> Toda migración debe ser idempotente, sin DROP automático, y backward-compatible.

---

## Identificación

```
Versión:         v[N]  (incremento de INFOUNO_DB_VERSION)
Versión previa:  v[N-1]
Branch:          migration/v[N]-[descripcion]
Ticket/motivo:   [descripción del cambio de negocio que requiere la migración]
```

---

## Tipo de Cambio

```
[ ] Nueva tabla completa          → usar CREATE TABLE en método create[Nombre]Table()
[ ] Nueva columna en tabla exist. → usar ALTER TABLE ADD COLUMN (con check de existencia)
[ ] Nuevo índice                  → usar ALTER TABLE ADD INDEX (con check de existencia)
[ ] Modificar ENUM                → usar ALTER TABLE MODIFY COLUMN
[ ] Datos de actualización masiva → método separado con UPDATE en lotes
```

> **PROHIBIDO:** `DROP TABLE`, `DROP COLUMN`, `TRUNCATE` sin respaldo previo confirmado.

---

## Diseño de la Migración

### Nueva tabla (si aplica)
```sql
-- Nombre: wp_infouno_[nombre]
-- Propósito: [descripción]

id              BIGINT UNSIGNED  PK AUTO_INCREMENT
tenant_id       INT UNSIGNED     NOT NULL   -- AISLAMIENTO OBLIGATORIO
[...]
created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP
updated_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP

PRIMARY KEY (id)
KEY tenant_id (tenant_id)
-- ¿Necesita índice compuesto? [especificar si hay queries de filtro múltiple]
```

### Nuevas columnas en tabla existente (si aplica)
```sql
-- Tabla: wp_infouno_[nombre]
-- Columna: [nombre_columna]  TIPO  DEFAULT  [NULL / NOT NULL]
-- Razón de negocio: [por qué se agrega]
-- Impacto en código existente: [qué clases/métodos deben actualizar su SELECT / INSERT]
```

### Modificación de ENUM (si aplica)
```sql
-- ENUM actual:    ENUM('val1', 'val2', 'val3')
-- ENUM nuevo:     ENUM('val1', 'val2', 'val3', 'val4')
-- Impacto:        MODIFY COLUMN es seguro para agregar valores al final
-- Whitelist code: Actualizar VALID_STATUSES en [archivo.php]
```

---

## Implementación en `Migrator.php`

### Pasos obligatorios

1. **Incrementar `DB_VERSION`:**
```php
const DB_VERSION = '[N]';
```

2. **Agregar comentario en el historial de versiones:**
```php
// vN — [descripción del cambio]
```

3. **Agregar condición incremental en `run()`:**
```php
if ( version_compare( $current, '1', '>=' ) && version_compare( $current, '[N]', '<' ) ) {
    $this->migrateTo[N]( $wpdb );
}
```

4. **Implementar `migrateTo[N]()`:**
```php
private function migrateTo[N]( \wpdb $wpdb ): void {
    $table = $wpdb->prefix . 'infouno_[nombre]';

    // Para ADD COLUMN — siempre con check de existencia
    $colExists = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = %s
               AND COLUMN_NAME  = '[columna]'",
            $table
        )
    );

    if ( ! $colExists ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN [columna] [TIPO] [DEFAULT] AFTER [columna_anterior]" );
    }
}
```

5. **Para nueva tabla:** Agregar método `create[Nombre]Table()` y llamarlo en `run()` después del bloque de upgrades incrementales.

---

## Checklist de Validación de la Migración

- [ ] `DB_VERSION` incrementado en `Migrator.php` Y en `Plugin.php` (si usa constante separada).
- [ ] El método `migrateTo[N]()` es idempotente — puede ejecutarse múltiples veces sin error.
- [ ] Cada `ALTER TABLE` verifica la existencia de la columna/índice antes de ejecutar.
- [ ] No hay `DROP TABLE` ni `DROP COLUMN` sin confirmación explícita del humano.
- [ ] Las nuevas columnas tienen DEFAULT apropiado (no NULL en columnas requeridas sin default).
- [ ] Las nuevas tablas tienen `tenant_id` con índice.
- [ ] `ia/branch-registry.md` actualizado con la nueva versión en el historial.
- [ ] `ia/rules/db-schema.md` actualizado con el nuevo schema.
- [ ] `ia/architecture.md` actualizado (sección 11 — Modelo de Datos).
- [ ] Las clases PHP que hacen INSERT / SELECT de la tabla afectada fueron actualizadas.
- [ ] La migración fue testeada en una instalación fresh (current = '0') Y en upgrade desde v[N-1].
