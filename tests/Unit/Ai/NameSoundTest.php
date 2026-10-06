<?php

use App\Services\Ai\NameSound;

/**
 * One name written several ways: Arabic against English, and English against
 * English. What is defended is both directions — the spellings that must be
 * one name are, and the names that are not one name stay apart whenever the
 * spelling gives anything to tell them by.
 */
it('reads every spelling of Mohamed as one name', function () {
    // On 2026-10-06 the employee list held all four English spellings.
    foreach (['Mohammed', 'Mohamed', 'Mohammad', 'Muhammad', 'MOHAMAD', 'محمد', 'مُحَمَّد'] as $spelling) {
        expect(NameSound::strict($spelling))->toContain('mhmd');
    }

    foreach (['Mohammed', 'Mohamed', 'Mohammad', 'Muhammad'] as $english) {
        expect(NameSound::score('محمد', $english))->toBe(2)
            ->and(NameSound::score($english, 'محمد'))->toBe(2)
            ->and(NameSound::score('Mohamed', $english))->toBe(2);
    }
});

dataset('same name', [
    // Arabic, English
    ['أحمد', 'Ahmed'],
    ['احمد', 'Ahmad'],
    ['خالد', 'Khalid'],
    ['خالد', 'Khaled'],
    ['عمر', 'Omar'],
    ['حسن', 'Hassan'],
    ['حسين', 'Hussein'],
    ['حسين', 'Hussain'],
    ['يوسف', 'Yousef'],
    ['يوسف', 'Youssef'],
    ['محمود', 'Mahmoud'],
    ['مصطفى', 'Mostafa'],
    ['مصطفى', 'Mustafa'],
    ['فيصل', 'Faisal'],
    ['فهد', 'Fahad'],
    ['ناصر', 'Nasser'],
    ['نايف', 'Naif'],
    ['أسامة', 'Osama'],
    ['هاني', 'Hani'],
    ['علي', 'Ali'],
    ['علي', 'Aly'],
    ['عادل', 'Adel'],
    ['عماد', 'Emad'],
    ['مدحت', 'Medhat'],
    ['هيثم', 'Haitham'],
    ['فتحي', 'Fathi'],
    ['أشرف', 'Ashraf'],
    ['جمال', 'Gamal'],
    ['جمال', 'Jamal'],
    ['تغريد', 'Taghreed'],
    ['سارة', 'Sarah'],
    ['فاطمة', 'Fatimah'],
    ['رنا', 'Rana'],
    ['ندى', 'Nada'],
    ['سلامة', 'Salameh'],
    ['سلامة', 'Salama'],
    ['خلاوي', 'Khalawi'],
    ['زهران', 'Zahran'],
    ['عبدالله', 'Abdullah'],
    ['عبدالرحمن', 'Abdulrahman'],
    ['عبدالرحمن', 'Abdelrahman'],
    ['وائل', 'Wael'],
    ['الحربي', 'Alharbi'],
    ['الحربي', 'Al-Harbi'],
    ['الغامدي', 'Alghamdi'],
    ['القحطاني', 'Al Qahtani'],
    ['العمودي', 'Alamoudi'],
    ['السيد', 'Elsayed'],
    ['الزهراني', 'Al-Zahrani'],
]);

it('joins an Arabic name to its English spelling', function (string $arabic, string $english) {
    expect(NameSound::score($arabic, $english))->toBe(2, "{$arabic} should be {$english}")
        ->and(NameSound::score($english, $arabic))->toBe(2, "{$english} should be {$arabic}");
})->with('same name');

it('joins the spellings that hide a long vowel, but only weakly', function (string $arabic, string $english) {
    // Arabic writes the ي or و; this English spelling does not show it.
    expect(NameSound::score($arabic, $english))->toBe(1, "{$arabic} should weakly be {$english}")
        ->and(NameSound::score($english, $arabic))->toBe(1);
})->with([
    ['إبراهيم', 'Ibrahim'],
    ['يوسف', 'Yusuf'],
    ['محمود', 'Mahmud'],
    ['نورة', 'Norah'],
    ['سمير', 'Samir'],
    ['وليد', 'Walid'],
    ['زياد', 'Ziad'],
]);

it('keeps apart the names a long vowel tells apart', function () {
    // The reason there are two strengths: Mahmoud is not Mohamed.
    expect(NameSound::score('محمد', 'Mahmoud'))->toBe(1)
        ->and(NameSound::score('محمود', 'Mahmoud'))->toBe(2)
        ->and(NameSound::score('محمود', 'Mohamed'))->toBe(1)
        ->and(NameSound::score('حسن', 'Hussein'))->toBe(1)
        ->and(NameSound::score('حسين', 'Hassan'))->toBe(1)
        ->and(NameSound::score('حسن', 'Hassan'))->toBe(2);
});

it('does not join names that share nothing but a letter or two', function (string $one, string $other) {
    expect(NameSound::score($one, $other))->toBe(0, "{$one} is not {$other}")
        ->and(NameSound::score($other, $one))->toBe(0);
})->with([
    ['محمد', 'Ahmed'],
    ['أحمد', 'Hamad Group'],
    ['أحمد', 'Hamed'],
    ['حامد', 'Ahmed'],
    ['خالد', 'Khalil'],
    ['علي', 'Alaa'],
    ['علي', 'Ola'],
    ['سارة', 'Samir'],
    ['فهد', 'Fahmy'],
    ['يوسف', 'Yasser'],
    ['Mohamed', 'Mahdi'],
    ['Ahmed', 'Hamed'],
    ['Sara', 'Samar'],
    ['Khalid', 'Khalifa'],
]);

it('cannot tell apart two names that differ only in vowels it does not keep', function () {
    // Its limit, and the reason it is a last resort that answers with a
    // list: these pairs are the same consonants.
    expect(NameSound::score('عمر', 'Ammar'))->toBeGreaterThan(0)
        ->and(NameSound::score('ناصر', 'Nasr'))->toBeGreaterThan(0)
        ->and(NameSound::score('صالح', 'Salah'))->toBeGreaterThan(0)
        ->and(NameSound::score('عبدالعزيز', 'Abdulaziz'))->toBeGreaterThan(0);
});

it('needs every word of the name asked for', function () {
    expect(NameSound::score('محمد سلامة', 'Mohammad Salameh'))->toBe(2)
        ->and(NameSound::score('سلامة محمد', 'Mohammad Salameh'))->toBe(2)
        ->and(NameSound::score('محمد سلامة', 'Mohammad Ibrahim Salameh'))->toBe(2)
        // One word that is nobody's is not this person.
        ->and(NameSound::score('محمد سلامة', 'Mohammad Zahran'))->toBe(0)
        ->and(NameSound::score('Mohamed Salama', 'Mohammad Salameh'))->toBe(2)
        ->and(NameSound::score('تغريد خلاوي', 'Taghreed Khalawi'))->toBe(2);
});

it('reads a name written as one word and as two', function () {
    expect(NameSound::score('عبد الله', 'Abdullah Omar'))->toBeGreaterThan(0)
        ->and(NameSound::score('عبدالله', 'Abdul Allah'))->toBeGreaterThan(0)
        ->and(NameSound::score('عبدالرحمن', 'Abdel Rahman Ali'))->toBe(2)
        ->and(NameSound::score('عبد الرحمن', 'Abdulrahman Ali'))->toBe(2)
        ->and(NameSound::score('عبد العزيز', 'Abdulaziz'))->toBeGreaterThan(0)
        // Part of a compound name still finds it, when it is long enough.
        ->and(NameSound::score('Rahman', 'Abdulrahman Ali'))->toBe(2);
});

it('treats the article as not part of the name, on either side', function () {
    expect(NameSound::score('الحربي', 'Mohammed Al Harbi'))->toBe(2)
        ->and(NameSound::score('حربي', 'Mohammed Alharbi'))->toBe(2)
        ->and(NameSound::score('Harbi', 'محمد الحربي'))->toBe(2)
        ->and(NameSound::words('Mohammed Al-Harbi'))->toBe(['mohammed', 'harbi'])
        // Ali and Elham are names, not an article and a name.
        ->and(NameSound::strict('Ali'))->toBe(['Aly'])
        ->and(NameSound::strict('Elham'))->toBe(['Alhm']);
});

it('reads a mailbox name as a name', function () {
    expect(NameSound::score('محمد سلامة', 'mohammad.salameh'))->toBe(2)
        ->and(NameSound::score('تغريد', 'taghreed_khalawi'))->toBe(2);
});

it('answers nothing for nothing', function () {
    expect(NameSound::score('', 'Mohamed'))->toBe(0)
        ->and(NameSound::score('محمد', ''))->toBe(0)
        ->and(NameSound::score('محمد'))->toBe(0)
        ->and(NameSound::score('123', 'Mohamed'))->toBe(0)
        ->and(NameSound::score('ال', 'Ali'))->toBe(0)
        ->and(NameSound::strict('42'))->toBe([]);
});
