# Financial Reconciliation Guide

How to verify that partner balances, commission, and withdrawals reconcile
with source data.

## Principles

1. **The ledger is the single source of truth for balances.** No stored
   balance column exists anywhere.
2. **Commission snapshots are frozen.** Historical figures never change
   when rates change.
3. **Every adjustment is auditable.** Nothing modifies a ledger entry after
   the fact.

## The available balance formula

For any partner at any point in time:
