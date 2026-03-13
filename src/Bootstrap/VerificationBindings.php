<?php

declare(strict_types=1);

namespace Maatify\Verification\Bootstrap;

use DI\ContainerBuilder;
use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\Verification\Domain\Contracts\TransactionManagerInterface;
use Maatify\Verification\Domain\Contracts\VerificationCodeGeneratorInterface;
use Maatify\Verification\Domain\Contracts\VerificationCodePolicyResolverInterface;
use Maatify\Verification\Domain\Contracts\VerificationCodeRepositoryInterface;
use Maatify\Verification\Domain\Contracts\VerificationCodeValidatorInterface;
use Maatify\Verification\Domain\Contracts\VerificationRateLimiterInterface;
use Maatify\Verification\Domain\Service\VerificationCodeGenerator;
use Maatify\Verification\Domain\Service\VerificationCodePolicyResolver;
use Maatify\Verification\Domain\Service\VerificationCodeValidator;
use Maatify\Verification\Infrastructure\RateLimiter\NullRateLimiter;
use Maatify\Verification\Infrastructure\RateLimiter\RedisRateLimiter;
use Maatify\Verification\Infrastructure\Repository\PdoVerificationCodeRepository;
use Maatify\Verification\Infrastructure\Transaction\PdoTransactionManager;
use PDO;
use Psr\Container\ContainerInterface;
use Redis;

class VerificationBindings
{
    /**
     * @param ContainerBuilder<\DI\Container> $builder
     */
    public static function register(ContainerBuilder $builder): void
    {
        $builder->addDefinitions([

            TransactionManagerInterface::class => function (ContainerInterface $c) {
                $pdo = $c->get(PDO::class);
                assert($pdo instanceof PDO);

                return new PdoTransactionManager($pdo);
            },

            VerificationCodeRepositoryInterface::class => function (ContainerInterface $c) {
                $pdo = $c->get(PDO::class);
                $clock = $c->get(ClockInterface::class);

                assert($pdo instanceof PDO);
                assert($clock instanceof ClockInterface);

                return new PdoVerificationCodeRepository($pdo, $clock);
            },

            VerificationCodePolicyResolverInterface::class => function () {
                return new VerificationCodePolicyResolver();
            },

            VerificationRateLimiterInterface::class => function (ContainerInterface $c) {

                $prefix = getenv('VERIFICATION_REDIS_PREFIX') ?: 'maatify:verification';

                try {
                    $redis = $c->get(Redis::class);
                    assert($redis instanceof Redis);

                    return new RedisRateLimiter($redis, $prefix);
                } catch (\Throwable) {
                    // Redis unavailable → fail open
                    return new NullRateLimiter();
                }
            },

            VerificationCodeGeneratorInterface::class => function (ContainerInterface $c) {

                $repo = $c->get(VerificationCodeRepositoryInterface::class);
                $resolver = $c->get(VerificationCodePolicyResolverInterface::class);
                $clock = $c->get(ClockInterface::class);
                $transactionManager = $c->get(TransactionManagerInterface::class);
                $rateLimiter = $c->get(VerificationRateLimiterInterface::class);

                assert($repo instanceof VerificationCodeRepositoryInterface);
                assert($resolver instanceof VerificationCodePolicyResolverInterface);
                assert($clock instanceof ClockInterface);
                assert($transactionManager instanceof TransactionManagerInterface);
                assert($rateLimiter instanceof VerificationRateLimiterInterface);

                $secret = getenv('VERIFICATION_SECRET');

                if (!$secret) {
                    throw new \RuntimeException('VERIFICATION_SECRET is required');
                }

                return new VerificationCodeGenerator(
                    $repo,
                    $resolver,
                    $clock,
                    $transactionManager,
                    $secret,
                    $rateLimiter
                );
            },

            VerificationCodeValidatorInterface::class => function (ContainerInterface $c) {

                $repo = $c->get(VerificationCodeRepositoryInterface::class);
                $clock = $c->get(ClockInterface::class);

                assert($repo instanceof VerificationCodeRepositoryInterface);
                assert($clock instanceof ClockInterface);

                $secret = getenv('VERIFICATION_SECRET');

                if (!$secret) {
                    throw new \RuntimeException('VERIFICATION_SECRET is required');
                }

                return new VerificationCodeValidator(
                    $repo,
                    $clock,
                    $secret
                );
            },

        ]);
    }
}
