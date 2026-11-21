<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\PayPal\Gateway;
use App\PayPal\PayPalCallException;
use App\Support\PayPalAccount;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

/**
 * A tool that asks one PayPal account one question.
 *
 * Everything the tools share is here: resolving the account, reading an agent's arguments strictly,
 * turning a failure into a sentence instead of a protocol error, and the envelope every answer
 * goes out in - which always says what the call cost and what is left. A subclass says what to
 * ask, and how many requests it may take.
 */
abstract class PayPalTool extends Tool
{
    /**
     * @return array<mixed>
     *
     * @throws PayPalCallException
     */
    abstract protected function answer(Request $request, Gateway $gateway, PayPalAccount $account): array;

    public function handle(Request $request, Gateway $gateway): Response|ResponseFactory
    {
        $gateway->resetLastSpent();

        try {
            $account = $gateway->account($this->text($request->get('account'), 'account') ?? '');
            $answer = $this->answer($request, $gateway, $account);
        } catch (PayPalCallException $e) {
            // an error RESULT, not a protocol error: the agent should read why and carry on
            return Response::error($e->getMessage());
        }

        return Response::structured([
            'account' => ['account' => $account->key, 'name' => $account->name, 'environment' => $account->environment->value],
            ...$answer,
            'budget' => $gateway->budget($account),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['account' => $schema->string()
            ->description('The key of the PayPal account to ask, as returned by list-accounts.')
            ->required()];
    }

    /**
     * Only what every answer carries; the rest is in each tool's description.
     *
     * @return array<string, Type>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'account' => $schema->object()->description('The PayPal account that answered, and whether it is live or sandbox.')->required(),
            'budget' => $schema->object()->description('Requests this call sent, token requests included, and what this server has used of its own limit for the account in the last 24 hours.')->required(),
        ];
    }

    /** @throws PayPalCallException */
    protected function text(mixed $value, string $argument): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_scalar($value)) {
            throw new PayPalCallException('`'.$argument.'` must be text.');
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** @throws PayPalCallException */
    protected function flag(mixed $value, string $argument): bool
    {
        return match (true) {
            $value === null, $value === false, $value === 'false', $value === 0, $value === '0', $value === '' => false,
            $value === true, $value === 'true', $value === 1, $value === '1' => true,
            default => throw new PayPalCallException('`'.$argument.'` must be true or false.'),
        };
    }

    /** @throws PayPalCallException */
    protected function whole(mixed $value, string $argument, int $min, int $max, int $default): int
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_numeric($value) && (int) $value == $value && (int) $value >= $min && (int) $value <= $max) {
            return (int) $value;
        }

        throw new PayPalCallException(sprintf('`%s` must be a whole number from %d to %d.', $argument, $min, $max));
    }

    /**
     * A moment an agent gave: a bare date, which is the start or end of that day in the configured
     * zone, or an ISO 8601 date and time with its own offset.
     *
     * @throws PayPalCallException
     */
    protected function instant(mixed $value, string $argument, bool $endOfDay): ?\DateTimeImmutable
    {
        $text = $this->text($value, $argument);

        if ($text === null) {
            return null;
        }

        $zone = new \DateTimeZone((string) config('paypalmcp.timezone') ?: 'UTC');

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return new \DateTimeImmutable($text.($endOfDay ? ' 23:59:59' : ' 00:00:00'), $zone);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})$/i', $text) === 1) {
            try {
                return new \DateTimeImmutable($text);
            } catch (\Exception) {
                // falls through to the refusal
            }
        }

        throw new PayPalCallException('`'.$argument.'` must be a date, YYYY-MM-DD, or a date and time with its offset, such as 2026-03-01T09:30:00+10:00.');
    }

    /**
     * `from`, required, and `to`, which defaults to now.
     *
     * @return array{\DateTimeImmutable, \DateTimeImmutable}
     *
     * @throws PayPalCallException
     */
    protected function range(Request $request): array
    {
        $from = $this->instant($request->get('from'), 'from', endOfDay: false)
            ?? throw new PayPalCallException('`from` is required: a date, YYYY-MM-DD.');
        $to = $this->instant($request->get('to'), 'to', endOfDay: true) ?? CarbonImmutable::now('UTC');

        if ($from > $to) {
            throw new PayPalCallException('`from` is after `to`.');
        }

        return [$from, $to];
    }

    /**
     * @return array<string, Type>
     */
    protected function rangeArguments(JsonSchema $schema): array
    {
        $zone = (string) config('paypalmcp.timezone');

        return [
            'from' => $schema->string()->description('Start of the range: YYYY-MM-DD, the start of that day in '.$zone.', or a date and time with its offset. PayPal keeps three years.')->required(),
            'to' => $schema->string()->description('End of the range, inclusive: YYYY-MM-DD, the end of that day in '.$zone.', or a date and time with its offset. Default: now.'),
        ];
    }
}
