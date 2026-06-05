# Guardrail: GitHub

> La AI opera con git local libremente. GitHub como plataforma queda fuera de su scope autónomo.
> _Última actualización: 2026-06-04._

---

## Nunca hacer de forma autónoma

- `git push origin main` (requiere aprobación explícita del usuario)
- Crear Pull Requests (`gh pr create` o similar)
- Cerrar o comentar Pull Requests
- Crear o cerrar Issues
- Modificar settings del repositorio
- Invitar colaboradores
- Modificar branch protection rules
- Cualquier operación con `gh` CLI que afecte el estado del repo en GitHub

## Sí permitido (libre)

- `git push origin dev`
- `git push origin feature/*`, `fix/*`, `chore/*`
- `git log`, `git status`, `git diff`
- `git branch`, `git checkout`, `git merge` (local)

## Push a main

Solo ejecutar si el usuario dijo explícitamente "sí" o "mergeá a main" en la
conversación actual. El silencio no es aprobación.
