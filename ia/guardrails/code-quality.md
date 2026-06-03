# Guardrails de Ingeniería: Calidad de Código y Despliegue

Este archivo regula la higiene del repositorio y la seguridad del código generado para evitar fallos catastróficos en el backend o frontend.

## 🚨 Líneas Rojas (Bloqueos Automáticos)
1. **Cero Código de Depuración en Producción:** Queda terminantemente PROHIBIDO dejar funciones como `var_dump()`, `print_r()`, `error_log()`, o `console.log()` en los archivos finales confirmados (mergeados), independientemente de si exponen datos sensibles o no.
2. **Sanitización Obligatoria de Entradas (XSS/SQLi):** Todo dato proveniente del Widget de chat o del formulario del Dashboard debe ser tratado como hostil. Queda prohibido insertar datos en la BD o renderizarlos en el HTML sin pasar por las funciones de escape de WordPress (`esc_sql()`, `sanitize_text_field()`, `esc_html()`).
3. **No Modificar Archivos Core o Astra directamente:** La IA tiene prohibido tocar archivos del núcleo de WordPress o de la carpeta raíz del tema Astra principal. Todo se extiende mediante el plugin custom o el tema hijo.
4. **No Credenciales Hardcodeadas:** Bajo ninguna circunstancia se deben escribir contraseñas, tokens de prueba, tokens JWT secretos o API keys directamente en el código fuente. Deben usarse variables de entorno o la base de datos segura.

## ✅ Buenas Prácticas Obligatorias
- **Un archivo, una responsabilidad:** Cada clase PHP o componente React debe tener una única responsabilidad. Queda prohibido crear "God Classes" o componentes que mezclen lógica de negocio con presentación.
- **Tipado estricto en PHP:** Todos los archivos PHP deben declarar `declare(strict_types=1);` al inicio. Los métodos deben incluir tipos en sus parámetros y valor de retorno.
- **Tipado estricto en TypeScript:** Queda prohibido usar `any` como tipo en el widget. Todo debe estar tipado explícitamente.
- **Nombres descriptivos:** Variables, funciones y clases deben nombrarse de forma que revelen su intención. Queda prohibido usar nombres genéricos como `$data`, `$arr`, `$temp` o `handleClick` sin contexto.
- **Linting antes de commit:** Antes de dar una tarea por completada, el código PHP debe pasar `composer package-lint` y el widget debe pasar su linter de TypeScript sin errores.

## 🛑 Acción Requerida si se cruza un límite
Si una sugerencia de código requiere violar una regla de sanitización, introducir código de depuración o modificar un archivo del core, la IA debe detenerse y reportar:
`[GUARDRAIL TRIGGERED: VIOLACIÓN DE CALIDAD O SANITIZACIÓN DE CÓDIGO]`
