export const DEFAULT_MATRIX_SUITE_SCENARIOS = Object.freeze([
  "MC_ATOMIC_01",
  "MC_RB_01",
  "MC_RB_03",
  "MC_RB_04",
  "MC_NUCLEAR_01",
  "MC_ATOMIC_04",
  "RT_ATOMIC_01",
  "RT_NUCLEAR_01",
  "OE_ATOMIC_01",
  "OE_NUCLEAR_01",
]);

export const FULL_MATRIX_SUITE_SCENARIOS = Object.freeze([
  "MC_ATOMIC_01",
  "MC_ATOMIC_02",
  "MC_RB_01",
  "MC_ATOMIC_03",
  "MC_RB_02",
  "MC_RB_03",
  "MC_RB_04",
  "MC_NUCLEAR_01",
  "MC_ATOMIC_04",
  "RT_ATOMIC_01",
  "RT_RB_01",
  "RT_RB_02",
  "RT_RB_03",
  "RT_NUCLEAR_01",
  "OE_ATOMIC_01",
  "OE_RB_01",
  "OE_RB_02",
  "OE_RB_03",
  "OE_NUCLEAR_01",
]);

export const FULL_SYNC_MC_SUITE_SCENARIOS = Object.freeze(
  FULL_MATRIX_SUITE_SCENARIOS.filter((scenarioId) => scenarioId.startsWith("MC_")),
);

export const FULL_SYNC_SUITE_SCENARIOS = Object.freeze([...FULL_MATRIX_SUITE_SCENARIOS]);
