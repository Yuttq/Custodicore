<?php

namespace Tests\Unit;

use App\Support\Sanitizer;
use PHPUnit\Framework\TestCase;

/**
 * Sanitizer::fullName(): a whole name in one string (the visitor's
 * intendedPdlName / pdlName). Same as name(), except a known suffix at the
 * end keeps its usual form ("III", not "Iii").
 */
class SanitizerFullNameTest extends TestCase
{
    public function test_ordinary_names_are_cleaned_exactly_like_name(): void
    {
        foreach (['JUAN DELA CRUZ', '  pedro   santos reyes ', 'McDonald', "Ma. Clara O'Neil-Santos", 'Juan Iii Santos'] as $value) {
            $this->assertSame(Sanitizer::name($value), Sanitizer::fullName($value), $value);
        }
        $this->assertSame('Juan Dela Cruz', Sanitizer::fullName('JUAN DELA CRUZ'));
        $this->assertNull(Sanitizer::fullName(null));
        $this->assertNull(Sanitizer::fullName('   '));
    }

    public function test_a_trailing_suffix_keeps_its_usual_form_and_a_lone_word_is_left_alone(): void
    {
        $this->assertSame('Juan Dela Cruz III', Sanitizer::fullName('Juan Dela Cruz III'));
        $this->assertSame('Juan Dela Cruz II', Sanitizer::fullName('juan dela cruz ii'));
        $this->assertSame('Pedro Reyes IV', Sanitizer::fullName('PEDRO REYES IV'));
        $this->assertSame('Pedro Reyes V', Sanitizer::fullName('Pedro Reyes V'));
        $this->assertSame('Juan Santos Jr.', Sanitizer::fullName('juan santos jr'));
        $this->assertSame('Juan Santos Sr.', Sanitizer::fullName('Juan Santos Sr.'));
        // One word: never treated as a suffix.
        $this->assertSame(Sanitizer::name('III'), Sanitizer::fullName('III'));
        // Name parts elsewhere are unchanged: name() still re-cases "III".
        $this->assertSame('Iii', Sanitizer::name('III'));
    }
}
