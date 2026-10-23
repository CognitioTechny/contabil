# Posting rules

Optional. A posting rule tells the package which two accounts an event of **your** system debits and credits, so the host can post without knowing account ids: it says "an invoice was issued for 2,500" and the rule supplies the accounts.

```php
$rules = service('contabil')->postingRules();

// Debit receivables, credit sales, whenever the event "invoice.issued" happens.
$rules->register($entity, 'invoice.issued', $receivablesId, $salesId);

// Conditions are equality tests on attributes you pass along; the first active match wins.
$rules->register($entity, 'payment.received', $bankId, $receivablesId, ['kind' => 'collection']);
$rules->register($entity, 'payment.received', $payablesId, $bankId, ['kind' => 'supplier']);

$result = $rules->post(
    $entity,
    'invoice.issued',
    250000,                      // minor units
    '2026-03-05',
    'Invoice 1042',
    ['kind' => 'standard'],      // attributes the conditions are tested against
    [
        'source_type'     => 'invoice',
        'source_id'       => '1042',
        'idempotency_key' => 'erp:invoice:1042:1',
    ],
);
```

`post()` creates and posts a two-line entry (so every posting rule applies: open period, active leaf accounts, balanced) and returns the same `EntryResult` as `createEntry()`, with `created = false` when the idempotency key was already used.

## No matching rule

When no active rule matches, `post()` returns `null` and writes **nothing**. The package keeps no queue of its own: the host keeps the event in its own pending list, tells the user which rule is missing, and calls `post()` again once it exists. Never post such an event to a guessed account.

## Rules

- Both accounts must be active accounts that accept entries and belong to the entity; they must differ.
- Conditions map attribute names to scalar values, compared as strings.
- Deactivate a rule with `setActive($entity, $ruleId, false)`; it is kept for the record and skipped when matching.
- Rules live in `acc_posting_rules`, scoped by entity. They are not exposed over HTTP in this version.

For anything that is not a simple debit-and-credit (taxes, multiple lines), build the entry yourself with `createEntry()` or `postBatch()`.
