<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Reports;

/**
 * A finished report, independent of the format it is delivered in. JSON uses
 * {@see toArray()} (amounts in minor units); CSV and PDF use the table, with
 * amounts as decimal strings.
 */
interface Report
{
    /**
     * Name used for the title and the download file, for example `trial-balance`.
     */
    public function slug(): string;

    public function title(): string;

    /**
     * Facts shown above the table (period, basis, totals), label => value.
     *
     * @return array<string, string>
     */
    public function summary(): array;

    /**
     * @return list<string>
     */
    public function tableHeader(): array;

    /**
     * @return list<list<string>>
     */
    public function tableRows(): array;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
