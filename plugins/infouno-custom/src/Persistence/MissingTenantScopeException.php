<?php
declare(strict_types=1);

namespace Infouno\SaaS\Persistence;

/**
 * Lanzada cuando una operación de repositorio se ejecuta sin un scope de
 * tenant válido (id <= 0). Es un error de programación — nunca debería
 * llegar al usuario. Se mapea a HTTP 500 en los controllers.
 *
 * Nunca exponer el mensaje crudo al cliente; loguear estructurado y devolver
 * respuesta genérica.
 */
final class MissingTenantScopeException extends \RuntimeException {}
