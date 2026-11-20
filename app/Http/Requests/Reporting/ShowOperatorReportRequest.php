<?php

declare(strict_types=1);

namespace App\Http\Requests\Reporting;

use App\Domain\Reporting\ValueObjects\ReportRange;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The report's range, as `/v1/usage` names it: `start_date` and `end_date`, strict UTC
 * `Y-m-d` dates, both or neither (D-65). Neither is the default range. A failed validation
 * goes back to the default report with field errors, never to the previous URL, so no query
 * runs with an invalid range.
 */
final class ShowOperatorReportRequest extends FormRequest
{
    /**
     * The default report: a full load of an invalid URL shows it, with the errors.
     *
     * @var string
     */
    protected $redirectRoute = 'report';

    /**
     * The route's `can:` middleware has already let only operators through (D-63).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Empty fields arrive as null, which `nullable` reads as not given.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'start_date' => ['bail', 'nullable', 'required_with:end_date', 'string', 'date_format:' . ReportRange::DATE_FORMAT],
            'end_date' => ['bail', 'nullable', 'required_with:start_date', 'string', 'date_format:' . ReportRange::DATE_FORMAT, 'before_or_equal:' . ReportRange::limitsFor(CarbonImmutable::now())['today']],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $enterAValidDate = 'Enter a valid date.';

        return [
            'start_date.required_with' => 'Enter a start date.',
            'start_date.string' => $enterAValidDate,
            'start_date.date_format' => $enterAValidDate,
            'end_date.required_with' => 'Enter an end date.',
            'end_date.string' => $enterAValidDate,
            'end_date.date_format' => $enterAValidDate,
            'end_date.before_or_equal' => "The end date can't be after today (UTC).",
        ];
    }

    /**
     * Order and span, checked only once both dates are valid, so a bad date never adds a
     * second, spurious error.
     *
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $errors = $validator->errors();
                [$startDate, $endDate] = [$this->input('start_date'), $this->input('end_date')];

                if ($errors->hasAny(['start_date', 'end_date']) || ! is_string($startDate) || ! is_string($endDate)) {
                    return;
                }

                $days = ReportRange::inclusiveDayCount($startDate, $endDate);

                if ($days < 1) {
                    $errors->add('start_date', 'The start date must be on or before the end date.');
                } elseif ($days > ReportRange::MAX_DAYS) {
                    $errors->add('end_date', sprintf('The range can be at most %d days.', ReportRange::MAX_DAYS));
                }
            },
        ];
    }

    /**
     * The validated range, or the default one when neither date was given.
     */
    public function range(): ReportRange
    {
        /** @var array{start_date?: ?string, end_date?: ?string} $validated */
        $validated = $this->validated();
        $startDate = $validated['start_date'] ?? null;
        $endDate = $validated['end_date'] ?? null;

        return $startDate !== null && $endDate !== null
            ? ReportRange::fromDates($startDate, $endDate)
            : ReportRange::defaultFor(CarbonImmutable::now());
    }
}
