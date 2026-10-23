<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Tests\Application\Chart;

use CodeIgniter\Test\CIUnitTestCase;
use CognitioTechny\Contabil\Domain\Chart\AccountType;
use CognitioTechny\Contabil\Domain\Chart\Nature;
use CognitioTechny\Contabil\Domain\Exception\ConflictException;
use CognitioTechny\Contabil\Domain\Exception\NotFoundException;
use CognitioTechny\Contabil\Domain\Exception\ValidationException;
use Tests\Support\ChartKit;

/**
 * The account invariants of the chart, with no database.
 *
 * @internal
 */
final class ChartInvariantsTest extends CIUnitTestCase
{
    private ChartKit $kit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kit = new ChartKit();
    }

    public function testNatureDerivesFromTypeAndContraFlipsIt(): void
    {
        $expected = [
            'ASSET'     => Nature::Debit,
            'EXPENSE'   => Nature::Debit,
            'LIABILITY' => Nature::Credit,
            'EQUITY'    => Nature::Credit,
            'REVENUE'   => Nature::Credit,
        ];

        foreach ($expected as $type => $nature) {
            $account = $this->kit->create->execute(1, 'T-' . $type, $type, $type);
            $contra  = $this->kit->create->execute(1, 'C-' . $type, 'Contra ' . $type, $type, null, true);

            $this->assertSame($nature, $account->nature);
            $this->assertFalse($account->isContra());
            $this->assertSame($nature->opposite(), $contra->nature);
            $this->assertTrue($contra->isContra());
        }
    }

    public function testCodeIsUniquePerEntityOnly(): void
    {
        $this->kit->create->execute(1, '1', 'Assets', 'asset');
        $other = $this->kit->store->create('Other', null, 'USD', 'ACCRUAL');

        $this->kit->create->execute($other, '1', 'Assets', 'asset');

        $this->expectException(ConflictException::class);
        $this->kit->create->execute(1, '1', 'Duplicate', 'asset');
    }

    public function testRootNeedsATypeAndAChildInheritsIt(): void
    {
        try {
            $this->kit->create->execute(1, '1', 'No type');
            $this->fail('A root account without a type must be rejected.');
        } catch (ValidationException) {
            // expected
        }

        $root  = $this->kit->create->execute(1, '2', 'Liabilities', 'LIABILITY');
        $child = $this->kit->create->execute(1, '2.1', 'Payables', null, $root->id);

        $this->assertSame(AccountType::Liability, $child->type);
        $this->assertSame(2, $child->level);
        $this->assertSame($root->id, $child->parentId);
    }

    public function testChildWithADifferentTypeIsRejected(): void
    {
        $root = $this->kit->create->execute(1, '1', 'Assets', 'asset');

        $this->expectException(ValidationException::class);
        $this->kit->create->execute(1, '1.1', 'Wrong', 'expense', $root->id);
    }

    public function testChildMayBeAContraAccountOfItsParentType(): void
    {
        $root   = $this->kit->create->execute(1, '1', 'Assets', 'asset');
        $contra = $this->kit->create->execute(1, '1.9', 'Accumulated depreciation', null, $root->id, true);

        $this->assertSame(AccountType::Asset, $contra->type);
        $this->assertSame(Nature::Credit, $contra->nature);
    }

    public function testParentLeafBecomesAGroupWhenItGetsAChild(): void
    {
        $root = $this->kit->create->execute(1, '1', 'Assets', 'asset');
        $this->assertTrue($root->acceptsEntries);

        $this->kit->create->execute(1, '1.1', 'Cash', null, $root->id);

        $this->assertFalse($this->kit->store->find(1, (int) $root->id)->acceptsEntries);
    }

    public function testLeafWithMovementCannotBecomeAGroup(): void
    {
        $leaf = $this->kit->create->execute(1, '1', 'Cash', 'asset');
        $this->kit->store->markMovement((int) $leaf->id);

        try {
            $this->kit->create->execute(1, '1.1', 'Child', null, $leaf->id);
            $this->fail('Adding a child under an account with movement must be rejected.');
        } catch (ConflictException) {
            $this->assertCount(1, $this->kit->store->all(1));
            $this->assertTrue($this->kit->store->find(1, (int) $leaf->id)->acceptsEntries);
        }
    }

    public function testCashAccountMustBeAnAssetLeaf(): void
    {
        $bank = $this->kit->create->execute(1, '1.1', 'Bank', 'asset', null, false, true, true);
        $this->assertTrue($bank->isCashAccount);

        $this->expectException(ValidationException::class);
        $this->kit->create->execute(1, '2.1', 'Cash-like liability', 'liability', null, false, true, true);
    }

    public function testUnknownParentIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->kit->create->execute(1, '1.1', 'Orphan', 'asset', 999);
    }

    public function testParentFromAnotherEntityIsNotFound(): void
    {
        $other  = $this->kit->store->create('Other', null, 'USD', 'ACCRUAL');
        $parent = $this->kit->create->execute($other, '1', 'Assets', 'asset');

        $this->expectException(NotFoundException::class);
        $this->kit->create->execute(1, '1.1', 'Cross-entity child', null, $parent->id);
    }

    public function testUnknownEntityIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->kit->create->execute(77, '1', 'Assets', 'asset');
    }

    public function testInactiveParentRejectsChildren(): void
    {
        $root = $this->kit->create->execute(1, '1', 'Assets', 'asset');
        $this->kit->deactivate->execute(1, (int) $root->id);

        $this->expectException(ValidationException::class);
        $this->kit->create->execute(1, '1.1', 'Child', null, $root->id);
    }

    public function testRequiredFieldsAreChecked(): void
    {
        foreach ([['', 'Name'], ['1 1', 'Name'], ['1', ' '], [str_repeat('9', 51), 'Name']] as [$code, $name]) {
            try {
                $this->kit->create->execute(1, $code, $name, 'asset');
                $this->fail('Invalid code or name accepted: ' . $code . ' / ' . $name);
            } catch (ValidationException) {
                // expected
            }
        }

        $this->assertSame([], $this->kit->store->all(1));
    }

    public function testMaskIsEnforcedPerLevel(): void
    {
        $kit  = new ChartKit('9.9.99');
        $root = $kit->create->execute(1, '1', 'Assets', 'asset');
        $kit->create->execute(1, '1.1', 'Current', null, $root->id);

        $this->expectException(ValidationException::class);
        $kit->create->execute(1, '1.9', 'Child', null, $kit->store->findByCode(1, '1.1')->id);
    }

    public function testMaskLimitsTheDepthOfTheChart(): void
    {
        $kit  = new ChartKit('9.9');
        $root = $kit->create->execute(1, '1', 'Assets', 'asset');
        $kit->create->execute(1, '1.1', 'Cash', null, $root->id);

        $this->expectException(ValidationException::class);
        $kit->create->execute(1, '1.1.1', 'Too deep', null, $kit->store->findByCode(1, '1.1')->id);
    }

    public function testUpdateChangesNameCodeAndReference(): void
    {
        $account = $this->kit->create->execute(1, '1', 'Assets', 'asset');

        $updated = $this->kit->update->execute(1, (int) $account->id, ['name' => ' Assets total ', 'code' => '10', 'reference_code' => 'R-1']);

        $this->assertSame('Assets total', $updated->name);
        $this->assertSame('10', $updated->code);
        $this->assertSame('R-1', $updated->referenceCode);

        $cleared = $this->kit->update->execute(1, (int) $account->id, ['reference_code' => '']);
        $this->assertNull($cleared->referenceCode);
    }

    public function testUpdateRejectsTakenCodesAndUnknownFields(): void
    {
        $this->kit->create->execute(1, '1', 'Assets', 'asset');
        $second = $this->kit->create->execute(1, '2', 'Liabilities', 'liability');

        try {
            $this->kit->update->execute(1, (int) $second->id, ['code' => '1']);
            $this->fail('A taken code must be rejected.');
        } catch (ConflictException) {
            // expected
        }

        $this->expectException(ValidationException::class);
        $this->kit->update->execute(1, (int) $second->id, ['level' => 5]);
    }

    public function testKeepingTheSameCodeIsNotAConflict(): void
    {
        $account = $this->kit->create->execute(1, '1', 'Assets', 'asset');

        $this->assertSame('1', $this->kit->update->execute(1, (int) $account->id, ['code' => '1', 'name' => 'Renamed'])->code);
    }

    public function testTypeAndNatureCannotChangeAfterMovement(): void
    {
        $account = $this->kit->create->execute(1, '1', 'Cash', 'asset');
        $this->kit->store->markMovement((int) $account->id);

        foreach ([['type' => 'expense'], ['contra' => true]] as $change) {
            try {
                $this->kit->update->execute(1, (int) $account->id, $change);
                $this->fail('A classification change after movement must be rejected.');
            } catch (ConflictException) {
                // expected
            }
        }

        $this->assertSame(AccountType::Asset, $this->kit->store->find(1, (int) $account->id)->type);
    }

    public function testTypeAndNatureCanChangeBeforeMovementOnARootLeaf(): void
    {
        $account = $this->kit->create->execute(1, '1', 'Misc', 'asset');

        $changed = $this->kit->update->execute(1, (int) $account->id, ['type' => 'expense']);
        $this->assertSame(Nature::Debit, $changed->nature);

        $contra = $this->kit->update->execute(1, (int) $account->id, ['contra' => true]);
        $this->assertSame(Nature::Credit, $contra->nature);
    }

    public function testChildrenAndGroupsKeepTheirType(): void
    {
        $root  = $this->kit->create->execute(1, '1', 'Assets', 'asset');
        $child = $this->kit->create->execute(1, '1.1', 'Cash', null, $root->id);

        foreach ([[(int) $root->id, 'expense'], [(int) $child->id, 'expense']] as [$id, $type]) {
            try {
                $this->kit->update->execute(1, $id, ['type' => $type]);
                $this->fail('A type change must be rejected for a group and for a child.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testLeafBecomesGroupOnlyWithoutMovement(): void
    {
        $free   = $this->kit->create->execute(1, '1', 'Free', 'asset');
        $moving = $this->kit->create->execute(1, '2', 'Moving', 'asset');
        $this->kit->store->markMovement((int) $moving->id);

        $this->assertFalse($this->kit->update->execute(1, (int) $free->id, ['accepts_entries' => false])->acceptsEntries);

        $this->expectException(ConflictException::class);
        $this->kit->update->execute(1, (int) $moving->id, ['accepts_entries' => false]);
    }

    public function testGroupWithChildrenCannotAcceptEntries(): void
    {
        $root = $this->kit->create->execute(1, '1', 'Assets', 'asset');
        $this->kit->create->execute(1, '1.1', 'Cash', null, $root->id);

        $this->expectException(ValidationException::class);
        $this->kit->update->execute(1, (int) $root->id, ['accepts_entries' => true]);
    }

    public function testCashFlagFollowsLeafAndAssetRules(): void
    {
        $account = $this->kit->create->execute(1, '1', 'Bank', 'asset');
        $this->assertTrue($this->kit->update->execute(1, (int) $account->id, ['is_cash_account' => true])->isCashAccount);

        $group = $this->kit->update->execute(1, (int) $account->id, ['accepts_entries' => false]);
        $this->assertFalse($group->isCashAccount, 'A group is never a cash account.');
    }

    public function testMovingUpdatesLevelsOfTheWholeSubtree(): void
    {
        $assets  = $this->kit->create->execute(1, '1', 'Assets', 'asset');
        $current = $this->kit->create->execute(1, '1.1', 'Current', null, $assets->id);
        $cash    = $this->kit->create->execute(1, '1.1.1', 'Cash', null, $current->id);
        $fixed   = $this->kit->create->execute(1, '1.2', 'Fixed', null, $assets->id);

        $moved = $this->kit->move->execute(1, (int) $current->id, $fixed->id);

        $this->assertSame($fixed->id, $moved->parentId);
        $this->assertSame(3, $moved->level);
        $this->assertSame(4, $this->kit->store->find(1, (int) $cash->id)->level);
        $this->assertFalse($this->kit->store->find(1, (int) $fixed->id)->acceptsEntries);
    }

    public function testMovingToTheRoot(): void
    {
        $assets  = $this->kit->create->execute(1, '1', 'Assets', 'asset');
        $current = $this->kit->create->execute(1, '1.1', 'Current', null, $assets->id);
        $cash    = $this->kit->create->execute(1, '1.1.1', 'Cash', null, $current->id);

        $moved = $this->kit->move->execute(1, (int) $current->id, null, '2');

        $this->assertNull($moved->parentId);
        $this->assertSame(1, $moved->level);
        $this->assertSame('2', $moved->code);
        $this->assertSame(2, $this->kit->store->find(1, (int) $cash->id)->level);
    }

    public function testMovingUnderItselfOrADescendantIsACycle(): void
    {
        $a = $this->kit->create->execute(1, '1', 'A', 'asset');
        $b = $this->kit->create->execute(1, '1.1', 'B', null, $a->id);
        $c = $this->kit->create->execute(1, '1.1.1', 'C', null, $b->id);

        foreach ([$a->id => $c->id, $b->id => $b->id] as $account => $newParent) {
            try {
                $this->kit->move->execute(1, $account, $newParent);
                $this->fail('A cycle must be rejected.');
            } catch (ValidationException $e) {
                $this->assertStringContainsString('cycle', $e->getMessage());
            }
        }

        $this->assertNull($this->kit->store->find(1, (int) $a->id)->parentId);
    }

    public function testMovingUnderAnotherTypeIsRejected(): void
    {
        $assets      = $this->kit->create->execute(1, '1', 'Assets', 'asset');
        $liabilities = $this->kit->create->execute(1, '2', 'Liabilities', 'liability');
        $child       = $this->kit->create->execute(1, '1.1', 'Cash', null, $assets->id);

        $this->expectException(ValidationException::class);
        $this->kit->move->execute(1, (int) $child->id, $liabilities->id);
    }

    public function testMovingUnderALeafWithMovementIsRejected(): void
    {
        $assets = $this->kit->create->execute(1, '1', 'Assets', 'asset');
        $cash   = $this->kit->create->execute(1, '2', 'Cash', 'asset');
        $this->kit->store->markMovement((int) $cash->id);
        $child = $this->kit->create->execute(1, '1.1', 'Petty', null, $assets->id);

        $this->expectException(ConflictException::class);
        $this->kit->move->execute(1, (int) $child->id, $cash->id);
    }

    public function testMovedSubtreeMustStillFitTheMask(): void
    {
        $kit = new ChartKit('9.9.99');
        $a   = $kit->create->execute(1, '1', 'A', 'asset');
        $b   = $kit->create->execute(1, '2', 'B', 'asset');
        $c   = $kit->create->execute(1, '2.1', 'C', null, $b->id);
        $kit->create->execute(1, '2.1.01', 'D', null, $c->id);

        // Under A, C would be level 2 and D level 3: the codes still fit.
        $kit->move->execute(1, (int) $c->id, $a->id);

        // Under D (level 3), C would be level 4, deeper than the mask allows.
        $this->expectException(ValidationException::class);
        $kit->move->execute(1, (int) $kit->store->findByCode(1, '2')->id, $kit->store->findByCode(1, '2.1.01')->id);
    }

    public function testMovingToTheSameParentIsANoOp(): void
    {
        $a = $this->kit->create->execute(1, '1', 'A', 'asset');
        $b = $this->kit->create->execute(1, '1.1', 'B', null, $a->id);

        $this->assertSame($b, $this->kit->move->execute(1, (int) $b->id, $a->id));
    }

    public function testDeactivateKeepsAccountsWithMovement(): void
    {
        $account = $this->kit->create->execute(1, '1', 'Cash', 'asset');
        $this->kit->store->markMovement((int) $account->id);

        $this->assertFalse($this->kit->deactivate->execute(1, (int) $account->id)->active);
        $this->assertFalse($this->kit->deactivate->execute(1, (int) $account->id)->active, 'Repeating is harmless.');
        $this->assertTrue($this->kit->reactivate->execute(1, (int) $account->id)->active);
    }

    public function testGroupCannotBeDeactivatedWhileChildrenAreActive(): void
    {
        $root  = $this->kit->create->execute(1, '1', 'Assets', 'asset');
        $child = $this->kit->create->execute(1, '1.1', 'Cash', null, $root->id);

        try {
            $this->kit->deactivate->execute(1, (int) $root->id);
            $this->fail('An active child must block the deactivation.');
        } catch (ConflictException) {
            // expected
        }

        $this->kit->deactivate->execute(1, (int) $child->id);
        $this->assertFalse($this->kit->deactivate->execute(1, (int) $root->id)->active);

        try {
            $this->kit->reactivate->execute(1, (int) $child->id);
            $this->fail('An inactive parent must block the reactivation.');
        } catch (ConflictException) {
            // expected
        }
    }

    public function testDeleteIsRefusedWithChildrenOrMovement(): void
    {
        $root  = $this->kit->create->execute(1, '1', 'Assets', 'asset');
        $child = $this->kit->create->execute(1, '1.1', 'Cash', null, $root->id);
        $this->kit->store->markMovement((int) $child->id);

        foreach ([$root->id, $child->id] as $id) {
            try {
                $this->kit->delete->execute(1, (int) $id);
                $this->fail('Delete must be refused.');
            } catch (ConflictException) {
                // expected
            }
        }

        $free = $this->kit->create->execute(1, '2', 'Unused', 'asset');
        $this->kit->delete->execute(1, (int) $free->id);
        $this->assertNull($this->kit->store->find(1, (int) $free->id));
    }

    public function testTreeIsNestedAndHidesInactiveBranches(): void
    {
        $root = $this->kit->create->execute(1, '1', 'Assets', 'asset');
        $this->kit->create->execute(1, '1.2', 'Fixed', null, $root->id);
        $cash = $this->kit->create->execute(1, '1.1', 'Cash', null, $root->id);
        $this->kit->create->execute(1, '2', 'Liabilities', 'liability');
        $this->kit->deactivate->execute(1, (int) $cash->id);

        $tree = $this->kit->tree->execute(1);

        $this->assertSame(['1', '2'], array_map(static fn ($n) => $n->account->code, $tree));
        $this->assertSame(['1.2'], array_map(static fn ($n) => $n->account->code, $tree[0]->children));

        $full = $this->kit->tree->execute(1, true);
        $this->assertSame(['1.1', '1.2'], array_map(static fn ($n) => $n->account->code, $full[0]->children));
        $this->assertSame('1.1', $full[0]->toArray()['children'][0]['code']);
    }
}
