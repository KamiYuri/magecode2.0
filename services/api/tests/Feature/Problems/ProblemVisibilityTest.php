<?php

declare(strict_types=1);

namespace Tests\Feature\Problems;

use App\Enums\PublishMode;
use App\Models\Problem;
use App\Services\ProblemVisibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesAcademicFixtures;
use Tests\TestCase;

/**
 * The mode × override × time matrix of D-16 §6.3. Read it as the specification
 * of what a student may see and submit: everything downstream (the listing
 * filter, `is_visible`, `is_submittable`, PROBLEM_LOCKED) resolves through
 * these two questions.
 *
 * Two readings the sources leave open, settled 2026-08-13:
 * a NULL time in auto mode means "not yet open" / "never closes", and
 * "locked" everywhere means the *effective* lock, not the manual flag alone.
 */
class ProblemVisibilityTest extends TestCase
{
    use CreatesAcademicFixtures;
    use RefreshDatabase;

    private ProblemVisibilityService $visibility;

    protected function setUp(): void
    {
        parent::setUp();
        $this->visibility = app(ProblemVisibilityService::class);
    }

    /**
     * @param  array<string, mixed>  $semesterPolicy
     * @param  array<string, mixed>  $problemState
     */
    #[DataProvider('publishMatrix')]
    public function test_effective_visibility(array $semesterPolicy, array $problemState, bool $expected): void
    {
        $problem = $this->problemUnder($semesterPolicy, $problemState);

        $this->assertSame($expected, $this->visibility->isVisible($problem));
    }

    /**
     * @param  array<string, mixed>  $semesterPolicy
     * @param  array<string, mixed>  $problemState
     */
    #[DataProvider('lockMatrix')]
    public function test_effective_lock(array $semesterPolicy, array $problemState, bool $expected): void
    {
        $problem = $this->problemUnder($semesterPolicy, $problemState);

        $this->assertSame($expected, $this->visibility->isLocked($problem));
    }

    /** @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: bool}> */
    public static function publishMatrix(): array
    {
        $auto = ['publish_mode' => PublishMode::Auto, 'allow_publish_override' => true];
        $manual = ['publish_mode' => PublishMode::Manual, 'allow_publish_override' => true];
        $autoPinned = ['publish_mode' => PublishMode::Auto, 'allow_publish_override' => false];
        $manualPinned = ['publish_mode' => PublishMode::Manual, 'allow_publish_override' => false];

        return [
            'auto: activation passed' => [$auto, ['activation_time' => '-1 hour'], true],
            'auto: activation ahead' => [$auto, ['activation_time' => '+1 hour'], false],
            'auto: no activation time is not yet open' => [$auto, [], false],
            'auto: manual flag is irrelevant' => [$auto, ['is_published' => true], false],

            'manual: flag set' => [$manual, ['is_published' => true], true],
            'manual: flag clear' => [$manual, ['is_published' => false], false],
            'manual: activation time is irrelevant' => [$manual, ['activation_time' => '-1 hour'], false],

            'override to manual wins when allowed' => [
                $auto, ['publish_mode_override' => PublishMode::Manual, 'is_published' => true], true,
            ],
            'override to auto wins when allowed' => [
                $manual, ['publish_mode_override' => PublishMode::Auto, 'activation_time' => '-1 hour'], true,
            ],
            'override ignored when the semester pins the mode' => [
                $autoPinned, ['publish_mode_override' => PublishMode::Manual, 'is_published' => true], false,
            ],
            'pinned manual ignores the problem override' => [
                $manualPinned, ['publish_mode_override' => PublishMode::Auto, 'activation_time' => '-1 hour'], false,
            ],
        ];
    }

    /** @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: bool}> */
    public static function lockMatrix(): array
    {
        $auto = ['lock_mode' => PublishMode::Auto, 'allow_lock_override' => true];
        $manual = ['lock_mode' => PublishMode::Manual, 'allow_lock_override' => true];
        $autoPinned = ['lock_mode' => PublishMode::Auto, 'allow_lock_override' => false];

        return [
            'auto: lock time passed' => [$auto, ['lock_time' => '-1 hour'], true],
            'auto: lock time ahead' => [$auto, ['lock_time' => '+1 hour'], false],
            'auto: no lock time never closes' => [$auto, [], false],
            'auto: manual flag is irrelevant' => [$auto, ['is_locked' => true], false],

            'manual: flag set' => [$manual, ['is_locked' => true], true],
            'manual: flag clear' => [$manual, ['is_locked' => false], false],
            'manual: lock time is irrelevant' => [$manual, ['lock_time' => '-1 hour'], false],

            'override to manual wins when allowed' => [
                $auto, ['lock_mode_override' => PublishMode::Manual, 'is_locked' => true], true,
            ],
            'override ignored when the semester pins the mode' => [
                $autoPinned, ['lock_mode_override' => PublishMode::Manual, 'is_locked' => true], false,
            ],
        ];
    }

    public function test_a_problem_is_submittable_only_while_visible_and_unlocked(): void
    {
        $open = $this->problemUnder(
            ['publish_mode' => PublishMode::Auto, 'lock_mode' => PublishMode::Auto],
            ['activation_time' => '-1 hour', 'lock_time' => '+1 hour'],
        );
        $closed = $this->problemUnder(
            ['publish_mode' => PublishMode::Auto, 'lock_mode' => PublishMode::Auto],
            ['activation_time' => '-2 hours', 'lock_time' => '-1 hour'],
        );
        $unopened = $this->problemUnder(
            ['publish_mode' => PublishMode::Auto, 'lock_mode' => PublishMode::Auto],
            ['lock_time' => '+1 hour'],
        );

        $this->assertTrue($this->visibility->isSubmittable($open));
        $this->assertFalse($this->visibility->isSubmittable($closed));
        $this->assertFalse($this->visibility->isSubmittable($unopened));
    }

    public function test_the_visible_scope_matches_the_computed_verdict(): void
    {
        // The listing cannot compute per row in PHP, so the scope has to agree
        // with the service or students would see a different set than the
        // is_visible flag claims.
        $semester = $this->semesterIn($this->courseIn($this->organizationWithAdmin()), [
            'publish_mode' => PublishMode::Auto,
            'allow_publish_override' => true,
        ]);
        $section = $this->sectionIn($semester);

        $open = Problem::factory()->for($section)->create(['activation_time' => now()->subHour()]);
        Problem::factory()->for($section)->create(['activation_time' => now()->addHour()]);
        Problem::factory()->for($section)->create();
        $overridden = Problem::factory()->for($section)->create([
            'publish_mode_override' => PublishMode::Manual,
            'is_published' => true,
        ]);

        $visible = Problem::query()->visibleIn($semester)->pluck('id')->all();

        sort($visible);
        $this->assertSame([$open->id, $overridden->id], $visible);
    }

    /**
     * @param  array<string, mixed>  $semesterPolicy
     * @param  array<string, mixed>  $problemState
     */
    private function problemUnder(array $semesterPolicy, array $problemState): Problem
    {
        $semester = $this->semesterIn($this->courseIn($this->organizationWithAdmin()), $semesterPolicy + [
            'publish_mode' => PublishMode::Auto,
            'lock_mode' => PublishMode::Auto,
            'allow_publish_override' => true,
            'allow_lock_override' => true,
        ]);

        foreach (['activation_time', 'lock_time'] as $key) {
            if (isset($problemState[$key]) && is_string($problemState[$key])) {
                $problemState[$key] = now()->modify($problemState[$key]);
            }
        }

        return Problem::factory()
            ->for($this->sectionIn($semester))
            ->create($problemState)
            ->load('section.semester');
    }
}
