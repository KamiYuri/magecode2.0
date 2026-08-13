<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ProgrammingLanguage;
use App\Services\SubmissionFileException;
use Tests\TestCase;

/**
 * Extension normalisation lives on the model so C2's request validation, the
 * storage service and the client-facing resource all answer the same way.
 */
class ProgrammingLanguageTest extends TestCase
{
    public function test_it_accepts_an_extension_from_its_allowlist(): void
    {
        $language = $this->language(['py']);

        $this->assertTrue($language->allowsExtension('py'));
    }

    public function test_it_normalises_a_leading_dot_and_case(): void
    {
        $language = $this->language(['py']);

        $this->assertTrue($language->allowsExtension('.PY'));
        $this->assertTrue($language->allowsExtension('.py'));
        $this->assertTrue($language->allowsExtension('Py'));
    }

    public function test_it_rejects_an_extension_outside_the_allowlist(): void
    {
        $language = $this->language(['py']);

        $this->assertFalse($language->allowsExtension('txt'));
        $this->assertFalse($language->allowsExtension(''));
    }

    public function test_a_language_can_accept_several_extensions(): void
    {
        $language = $this->language(['cpp', 'cc', 'cxx']);

        $this->assertTrue($language->allowsExtension('cc'));
        $this->assertTrue($language->allowsExtension('cxx'));
        $this->assertFalse($language->allowsExtension('c'));
    }

    public function test_an_empty_allowlist_accepts_nothing(): void
    {
        $language = $this->language([]);

        $this->assertFalse($language->allowsExtension('py'));
    }

    public function test_the_default_extension_is_the_first_entry(): void
    {
        $this->assertSame('py', $this->language(['py'])->defaultExtension());
        $this->assertSame('cpp', $this->language(['cpp', 'cc', 'cxx'])->defaultExtension());
    }

    public function test_a_language_without_extensions_has_no_default(): void
    {
        $language = $this->language([]);

        $this->expectException(SubmissionFileException::class);

        $language->defaultExtension();
    }

    /** @param  list<string>  $extensions */
    private function language(array $extensions): ProgrammingLanguage
    {
        return ProgrammingLanguage::factory()->make(['file_extensions' => $extensions]);
    }
}
