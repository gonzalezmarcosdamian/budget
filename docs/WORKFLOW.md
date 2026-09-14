# Cómo se trabaja en este proyecto

Metodología ágil adaptada a un equipo de una persona más agentes. La ceremonia
que no produce software mejor no está.

## Roles

| Rol | Quién | Responsabilidad |
|---|---|---|
| Product Owner | vos | Qué se construye y en qué orden. Única voz que recorta alcance. |
| Orquestador | agente `entrega` | Lleva una historia de punta a punta y garantiza la Definition of Done. |
| Especialistas | `planificador`, `revisor-php`, `revisor-seguridad`, `revisor-datos`, `evaluador-extraccion` | Una competencia cada uno, contexto acotado, veredicto explícito. |
| Puerta de calidad | `qa-integracion` | Evidencia real antes de desplegar. No opina: corre comandos. |

La separación importa: un agente que implementa y después se revisa a sí mismo
tiende a aprobar su propio trabajo. Las revisiones corren en subagentes con
contexto propio justamente para que lleguen sin haberse enamorado del código.

## El ciclo de una historia

```
   Refinamiento          Construcción              Verificación           Entrega
  ┌──────────────┐     ┌────────────────┐      ┌──────────────────┐    ┌─────────┐
  │ planificador │ ──> │ TDD: rojo →    │ ──>  │ revisor-php      │──> │ qa-     │──> deploy
  │              │     │ verde →        │      │ revisor-seguridad│    │ integra-│
  │ DoR cumplida │     │ refactor       │      │ revisor-datos    │    │ cion    │
  └──────────────┘     └────────────────┘      └──────────────────┘    └─────────┘
                                                  ↑ los tres en paralelo
```

**Las tres revisiones corren a la vez** sobre el mismo diff: son independientes y
no comparten estado. La implementación no se paraleliza: partir en frentes algo
que después hay que integrar cuesta más de lo que ahorra.

## Límite de trabajo en curso: 1

Una historia a la vez, terminada de verdad, antes de empezar la siguiente.
Tres historias al 80% valen cero: no hay nada que un usuario pueda usar.

## Definition of Ready

Una historia no entra a construcción sin esto:

- [ ] Está escrita como *Como… quiero… para…*
- [ ] Tiene criterios de aceptación **verificables** (un comando o un tap en Telegram)
- [ ] Declara explícitamente qué queda **fuera de alcance**
- [ ] No depende de una decisión que todavía no se tomó
- [ ] Entra en menos de una semana de trabajo; si no, se parte

## Definition of Done

No se declara terminada sin todo esto:

- [ ] Criterios de aceptación cumplidos y demostrados
- [ ] Tests nuevos para la lógica nueva, y toda la suite en verde
- [ ] `docker compose exec app php tests/run.php` en verde (incluye integración)
- [ ] Lint limpio en PHP 8.2 y 8.4
- [ ] `revisor-php` y `revisor-seguridad` sin hallazgos bloqueantes
- [ ] `revisor-datos` si hubo migración
- [ ] `evaluador-extraccion` si cambió un prompt, un proveedor o el router
- [ ] `qa-integracion` con veredicto `LISTO PARA DESPLEGAR`
- [ ] Documentación actualizada si cambió el comportamiento visible
- [ ] Commit con mensaje convencional (`feat:`, `fix:`, `refactor:`…)

## TDD: cuándo sí y cuándo no

**Sí**, sin excepción, para lógica con reglas: parseo de importes, fechas
relativas, categorización, cálculo de totales, transiciones de estado. Ahí el
test primero no es disciplina, es la forma más rápida de entender el problema.

**No** para cableado, DTOs sin lógica, o adaptadores que sólo traducen formatos.
Escribir un test que verifica que un constructor asigna propiedades no protege
de nada y hay que mantenerlo igual.

La regla práctica: si podés describir el caso que falla antes de escribirlo,
escribí el test primero.

## Tablero

GitHub Issues, con estas etiquetas:

| Etiqueta | Significado |
|---|---|
| `fase-1` … `fase-4` | A qué fase del plan pertenece |
| `bloqueada` | Espera una decisión o algo externo |
| `riesgo-datos` | Toca la base de producción |
| `riesgo-privacidad` | Toca datos financieros de terceros |
| `deuda` | Cosa a arreglar que no bloquea la entrega |

Sin sprints de dos semanas. La unidad de planificación es la **fase** del plan
técnico, y cada fase termina en algo que se puede usar a diario.

## Quality gates automáticos

Corren solos, sin que nadie se acuerde:

| Cuándo | Qué | Dónde |
|---|---|---|
| Al editar un `.php` | `php -l` sobre el archivo | hook `PostToolUse` |
| Al cerrar un turno | tests unitarios | hook `Stop` |
| En cada push y PR | lint + unitarios en 8.2 y 8.4 | CI |
| En cada push y PR | migraciones desde cero + integración contra MariaDB | CI |
| Sólo en `main` | despliegue por FTP + verificación del webhook | CI |

El deploy depende de los dos jobs anteriores: si algo está rojo, no sale.

## Reglas de convivencia con los agentes

1. **Contexto acotado.** Cada subagente recibe el diff y su checklist, no el
   proyecto entero. Un revisor con demasiado contexto reporta ruido.
2. **Veredicto explícito.** `BLOQUEA`, `ADVIERTE` o `LIMPIO`. Una revisión que
   termina en "en general está bien" no sirve para decidir nada.
3. **Un informe limpio es válido.** Inventar hallazgos para justificar la
   revisión entrena a ignorarlas.
4. **Evidencia, no confianza.** `qa-integracion` reporta salida real de comandos.
   Si no pudo correr algo, el veredicto es "no verificado", nunca
   "probablemente ande".
5. **El alcance no se recorta solo.** Si algo no entró, se dice. Decidir recortar
   es del Product Owner.
