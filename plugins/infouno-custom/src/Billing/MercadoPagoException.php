<?php
declare(strict_types=1);

namespace Infouno\SaaS\Billing;

/** Error de la API de MercadoPago (status >= 400 o respuesta no parseable). */
final class MercadoPagoException extends \RuntimeException {}
