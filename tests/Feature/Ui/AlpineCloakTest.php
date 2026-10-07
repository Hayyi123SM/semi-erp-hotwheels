<?php

namespace Tests\Feature\Ui;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards `x-cloak`, the rule that hides Alpine markup until Alpine boots.
 *
 * This is a cascade assertion, not a rendering one: PHPUnit cannot see that the
 * "Tandai gagal dicetak" dialog flashed on screen and then vanished. What it can
 * do is pin the part that actually regressed, which is where the rule sits.
 *
 * `[x-cloak] { display: none }` used to live in `@layer components`. That was
 * wrong in a way no call site can patch around: a cloaked element nearly always
 * also carries a layout class such as `flex`, or a component class such as
 * `btn-primary` that `@apply`es a display value. Those live in `@layer
 * utilities` and `@layer components`, all of them at specificity `0,1,0`, and
 * the layers are emitted utilities-last. So the class display won, the element
 * rendered, Alpine booted, removed the attribute, and the element disappeared --
 * a flash on every load, on every one of the 17 views that use `x-cloak`.
 *
 * Unlayered author CSS outranks every author layer, so the rule now sits outside
 * all of them and those class combinations are correct as written. Alpine still
 * removes the attribute on init and `x-show` still writes an inline style, so
 * nothing stays hidden once the page is live.
 *
 * These tests deliberately say nothing about which classes may sit on a cloaked
 * element. Under an unlayered rule every combination is safe, and a test that
 * banned them would only teach the next person to strip working classes or bolt
 * on a helper class whose entire job is to defeat specificity.
 */
class AlpineCloakTest extends TestCase
{
    private function css(): string
    {
        return (string) file_get_contents(resource_path('css/app.css'));
    }

    /**
     * CSS with comments and quoted strings blanked out, offsets preserved.
     *
     * Length is held constant so an offset computed against the original text
     * stays valid against the stripped text. Without this, prose in a comment
     * that happens to contain braces would unbalance every brace count below.
     */
    private function withoutCommentsAndStrings(string $css): string
    {
        $out = '';
        $length = strlen($css);

        for ($i = 0; $i < $length; $i++) {
            if (substr($css, $i, 2) === '/*') {
                $end = strpos($css, '*/', $i + 2);
                $end = $end === false ? $length : $end + 2;
                $out .= str_repeat(' ', $end - $i);
                $i = $end - 1;

                continue;
            }

            $char = $css[$i];

            if ($char === '"' || $char === "'") {
                $j = $i + 1;

                while ($j < $length) {
                    if ($css[$j] === '\\') {
                        $j += 2;

                        continue;
                    }

                    if ($css[$j] === $char) {
                        $j++;

                        break;
                    }

                    $j++;
                }

                $out .= str_repeat(' ', $j - $i);
                $i = $j - 1;

                continue;
            }

            $out .= $char;
        }

        return $out;
    }

    /**
     * The `@layer` selectors still open around `$offset`.
     *
     * A brace walk rather than a regex on `@layer`, because only the blocks
     * genuinely open at the offset decide the answer. A rule that merely
     * mentions `@layer` in prose, or a layer that opened and closed earlier,
     * proves nothing.
     *
     * @return list<string>
     */
    private function layersOpenAt(string $css, int $offset): array
    {
        $stack = [];
        $depth = 0;

        for ($i = 0; $i < $offset; $i++) {
            $char = $css[$i];

            if ($char === '}') {
                $depth--;

                if ($depth >= 0) {
                    array_pop($stack);
                }

                continue;
            }

            if ($char !== '{') {
                continue;
            }

            $depth++;

            if ($depth !== 1) {
                continue;
            }

            $start = $i;

            while ($start > 0 && $css[$start - 1] !== '}' && $css[$start - 1] !== ';') {
                $start--;
            }

            $stack[] = trim(substr($css, $start, $i - $start));
        }

        return array_values(array_filter(
            $stack,
            fn (string $selector): bool => str_starts_with($selector, '@layer'),
        ));
    }

    #[Test]
    public function the_cloak_rule_is_not_inside_any_layer(): void
    {
        $css = $this->css();

        $this->assertMatchesRegularExpression(
            '/\[x-cloak\]\s*\{[^}]*display:\s*none/',
            $css,
            'Aturan [x-cloak] harus tetap ada dan tetap menyembunyikan elemen.',
        );

        $offset = strpos($css, '[x-cloak]');
        $this->assertIsInt($offset);

        $this->assertSame(
            [],
            $this->layersOpenAt($this->withoutCommentsAndStrings($css), $offset),
            'Aturan [x-cloak] tidak boleh berada di dalam @layer apa pun. Layer '
                .'utilities dan components berada setelah components dengan specificity '
                .'yang sama, sehingga class display pada elemen yang di-cloak akan menang '
                .'dan elemennya terlihat sebelum Alpine boot.',
        );
    }

    #[Test]
    public function the_stylesheet_defines_exactly_one_cloak_rule(): void
    {
        $code = $this->withoutCommentsAndStrings($this->css());

        $this->assertSame(
            1,
            substr_count($code, '[x-cloak]'),
            'Hanya boleh ada SATU aturan [x-cloak] di seluruh stylesheet. Aturan '
                .'kedua yang diletakkan di dalam @layer akan menimpa aturan unlayered '
                .'dan mengembalikan flash ini tanpa terlihat di diff yang kecil.',
        );
    }

    #[Test]
    public function the_cloak_rule_needs_no_important_to_survive_the_build(): void
    {
        $css = $this->css();
        $offset = strpos($css, '[x-cloak]');
        $this->assertIsInt($offset);

        $block = substr($css, $offset, (int) strpos($css, '}', $offset) - $offset + 1);

        $this->assertStringNotContainsString(
            '!important',
            $block,
            'Cloak tidak butuh !important kalau aturannya unlayered. Kalau ini '
                .'muncul berarti ada yang salah: `!important` di author stylesheet '
                .'justru mengalahkan inline style, sehingga `x-show` ikut mati.',
        );
    }
}
