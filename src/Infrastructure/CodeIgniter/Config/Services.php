<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Infrastructure\CodeIgniter\Config;

use CodeIgniter\Config\BaseService;
use CognitioTechny\Contabil\Application\Configuration\Settings;
use CognitioTechny\Contabil\Application\Defaults\DenyAllAuthorizer;
use CognitioTechny\Contabil\Application\Defaults\NullEventDispatcher;
use CognitioTechny\Contabil\Application\Defaults\StaticCurrentEntity;
use CognitioTechny\Contabil\Application\Defaults\StaticCurrentUser;
use CognitioTechny\Contabil\Application\Defaults\SystemClock;
use CognitioTechny\Contabil\Application\Ports\AuthorizerInterface;
use CognitioTechny\Contabil\Application\Ports\ClockInterface;
use CognitioTechny\Contabil\Application\Ports\CurrentEntityInterface;
use CognitioTechny\Contabil\Application\Ports\CurrentUserInterface;
use CognitioTechny\Contabil\Infrastructure\CodeIgniter\UseCases;
use Config\Database;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Services of the package. To plug in the host adapters for the ports, define a
 * method with the same name in the host's `app/Config/Services.php`; the host
 * version wins. For example:
 *
 *     public static function contabilAuthorizer(bool $getShared = true): AuthorizerInterface
 *     {
 *         return static::getSharedInstance('contabilAuthorizer') ?? new HostAuthorizer();
 *     }
 */
class Services extends BaseService
{
    public static function contabilSettings(bool $getShared = true): Settings
    {
        if ($getShared) {
            return static::getSharedInstance('contabilSettings');
        }

        return config(Contabil::class)->settings();
    }

    /**
     * Denies everything by default, so the HTTP API stays closed until the host
     * provides an authorizer. Commands do not use it.
     */
    public static function contabilAuthorizer(bool $getShared = true): AuthorizerInterface
    {
        if ($getShared) {
            return static::getSharedInstance('contabilAuthorizer');
        }

        return new DenyAllAuthorizer();
    }

    public static function contabilCurrentUser(bool $getShared = true): CurrentUserInterface
    {
        if ($getShared) {
            return static::getSharedInstance('contabilCurrentUser');
        }

        return new StaticCurrentUser();
    }

    public static function contabilCurrentEntity(bool $getShared = true): CurrentEntityInterface
    {
        if ($getShared) {
            return static::getSharedInstance('contabilCurrentEntity');
        }

        return new StaticCurrentEntity(static::contabilSettings()->defaultEntityId);
    }

    public static function contabilClock(bool $getShared = true): ClockInterface
    {
        if ($getShared) {
            return static::getSharedInstance('contabilClock');
        }

        return new SystemClock();
    }

    public static function contabilEventDispatcher(bool $getShared = true): EventDispatcherInterface
    {
        if ($getShared) {
            return static::getSharedInstance('contabilEventDispatcher');
        }

        return new NullEventDispatcher();
    }

    public static function contabil(bool $getShared = true): UseCases
    {
        if ($getShared) {
            return static::getSharedInstance('contabil');
        }

        $settings = static::contabilSettings();

        return new UseCases(
            $settings,
            Database::connect($settings->dbGroup),
            static::contabilClock(),
        );
    }
}
