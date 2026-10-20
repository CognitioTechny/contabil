<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Testing\Contract;

use CodeIgniter\Test\CIUnitTestCase;
use CognitioTechny\Contabil\Application\Ports\AuthorizerInterface;

/**
 * Contract every AuthorizerInterface implementation must satisfy.
 */
abstract class AuthorizerContractTest extends CIUnitTestCase
{
    abstract protected function createSubject(): AuthorizerInterface;

    public function testAnswersWithABooleanForAKnownPermission(): void
    {
        $this->assertIsBool($this->createSubject()->can('contabil.chart.view'));
    }

    public function testAnswersWithABooleanForAnEntityScopedCheck(): void
    {
        $this->assertIsBool($this->createSubject()->can('contabil.chart.edit', 1));
        $this->assertIsBool($this->createSubject()->can('contabil.chart.edit', 'entity-a'));
    }

    public function testDoesNotThrowForAnUnknownPermission(): void
    {
        $this->assertIsBool($this->createSubject()->can('contabil.does.not.exist'));
    }

    public function testAnswerIsRepeatable(): void
    {
        $subject = $this->createSubject();

        $this->assertSame($subject->can('contabil.reports.view', 1), $subject->can('contabil.reports.view', 1));
    }
}
