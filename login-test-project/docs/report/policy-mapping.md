# GBCR/RCLR Policy Mapping

This section maps the finalized conflict policy to concrete runtime behavior and test coverage.

## Policy-to-runtime mapping

| Policy rule | Runtime behavior | Scenario evidence |
| --- | --- | --- |
| Atomic recovery when replayed path is still valid | Session keeps current logical position; no fallback re-answer required | `MC_ATOMIC_01`, `MC_ATOMIC_02`, `MC_ATOMIC_03`, `MC_ATOMIC_04`, `RT_ATOMIC_01`, `OE_ATOMIC_01` |
| Rollback when passed edge/node becomes invalid | Resolver drops answers at/after fallback boundary and moves cursor to fallback stable node | `MC_RB_01`, `MC_RB_02`, `MC_RB_03`, `MC_RB_04`, `RT_RB_01`, `RT_RB_02`, `RT_RB_03`, `OE_RB_01`, `OE_RB_02`, `OE_RB_03` |
| Fallback node must be answered fresh | `state.answers` must not already contain fallback node answer after reconciliation | Asserted in matrix/sync automation and API matrix tests |
| Nuclear restart when no stable node is derivable | Resolver restarts from new entry path with previous path answers discarded | `MC_NUCLEAR_01`, `RT_NUCLEAR_01`, `OE_NUCLEAR_01` |
| Text/label-only edits are non-conflicting | No structural conflict; traversal continues without rollback | `MC_ATOMIC_04` |
| Zombie question prevention | Current node and visible node set remain reachable from active schema | API conflict tests (`must_not_show_unreachable`, visibility assertions) |

## API contract signals used by mobile/client automation

- `version_sync.mismatch_detected`
- `version_sync.conflict_detected`
- `version_sync.recovery_strategy`
- `version_sync.dropped_answers`
- `state.current_question`
- `state.answers`
- `state.visible_questions`
- `state.session_status`

## Expected strategy values in current implementation

- Atomic path-safe reconciliation: `atomic_recovery`
- Rollback/fallback and restart-from-entry behavior: `rollback`

Note: nuclear semantics are modeled as restart-from-entry behavior but currently reported with `rollback` strategy value.
