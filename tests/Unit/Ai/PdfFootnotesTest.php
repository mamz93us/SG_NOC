<?php

use App\Services\Ai\PdfFootnotes;

/**
 * Notes moved from the foot of the page to the article that carries their
 * mark. On page 58 of the labor law, footnote 77 marks Article 211 as
 * repealed; left at the foot, the assistant read it as Article 77. The page
 * texts below are shaped the way gpt-4o returned the labor law's pages in
 * trials, and the notes keep the page reading's words.
 */
uses(Tests\TestCase::class);

it('moves each note from the foot of the page to the end of the article its mark is on, in both languages', function () {
    $original = "### المادة السادسة والسبعون: 40\nإذا لم يراع الطرف الذي أنهى العقد المهلة المحددة للإشعار.\n\n"
        ."### المادة السابعة والسبعون: 41\nما لم يتضمن العقد تعويضًا محددًا.\n1. أجر خمسة عشر يومًا عن كل سنة.\n2. أجر المدة الباقية من العقد.\n\n"
        ."40- عدلت هذه المادة بالمرسوم الملكي رقم (م/46) وتاريخ 1436/6/5هـ.\n41- عدلت هذه المادة بالمرسوم الملكي رقم (م/46) وتاريخ 1436/6/5هـ.";
    $english = "### Article 76: 40\nIf the party does not observe the notice period.\n\n"
        ."### Article 77: 41\nUnless the contract specifies a defined compensation.\n1. Fifteen days' wage for each year.\n2. The wage for the remaining term.\n\n"
        ."40- This article was amended by Royal Decree No. (M/46) dated 1436/6/5H.\n41- This article was amended by Royal Decree No. (M/46) dated 1436/6/5H.";

    $page = PdfFootnotes::apply($original, $english, [40 => 'المادة السادسة والسبعون: 40', 41 => 'المادة السابعة والسبعون: 41']);

    expect($page['original'])->toBe(
        "### المادة السادسة والسبعون:\nإذا لم يراع الطرف الذي أنهى العقد المهلة المحددة للإشعار.\n\n[^40]: عدلت هذه المادة بالمرسوم الملكي رقم (م/46) وتاريخ 1436/6/5هـ.\n\n"
        ."### المادة السابعة والسبعون:\nما لم يتضمن العقد تعويضًا محددًا.\n1. أجر خمسة عشر يومًا عن كل سنة.\n2. أجر المدة الباقية من العقد.\n\n[^41]: عدلت هذه المادة بالمرسوم الملكي رقم (م/46) وتاريخ 1436/6/5هـ."
    )->and($page['english'])->toBe(
        "### Article 76:\nIf the party does not observe the notice period.\n\n[^40]: This article was amended by Royal Decree No. (M/46) dated 1436/6/5H.\n\n"
        ."### Article 77:\nUnless the contract specifies a defined compensation.\n1. Fifteen days' wage for each year.\n2. The wage for the remaining term.\n\n[^41]: This article was amended by Royal Decree No. (M/46) dated 1436/6/5H."
    );
});

it('places notes whose marks the reading dropped by the headings the marks are on, including a note run on over two lines', function () {
    $original = "## المادة التاسعة والأربعون بعد المائة:\n(ملغاة)\n\n## المادة الحادية والخمسون بعد المائة:\n1. للمرأة العاملة الحق في إجازة وضع.\n\n"
        ."## المادة الثالثة والخمسون بعد المائة:\nعلى صاحب العمل توفير الرعاية الطبية.\n\n"
        ."### الملاحظات:\n- 53: ألغيت بالمرسوم الملكي رقم (م/5) وتاريخ 1442/1/7هـ.\n- 55: عدلت ودمج بها حكم المادة الثانية والخمسون بعد المائة\nوعدلت هذه المادة بناء على المرسوم الملكي رقم (م/44).";
    $english = "## Article 149:\n(Repealed)\n\n## Article 151:\n1. A female worker is entitled to maternity leave.\n\n## Article 153:\nThe employer must provide medical care.\n\n"
        ."### Notes:\n- 53: Repealed by Royal Decree No. (M/5) dated 1442/1/7H.\n- 55: Amended and merged with Article 152, and amended by Royal Decree No. (M/44).";

    $page = PdfFootnotes::apply($original, $english, [53 => 'المادة التاسعة والأربعون بعد المائة:', 55 => 'المادة الحادية والخمسون بعد المائة:']);

    expect($page['original'])->toBe(
        "## المادة التاسعة والأربعون بعد المائة:\n(ملغاة)\n\n[^53]: ألغيت بالمرسوم الملكي رقم (م/5) وتاريخ 1442/1/7هـ.\n\n"
        ."## المادة الحادية والخمسون بعد المائة:\n1. للمرأة العاملة الحق في إجازة وضع.\n\n[^55]: عدلت ودمج بها حكم المادة الثانية والخمسون بعد المائة وعدلت هذه المادة بناء على المرسوم الملكي رقم (م/44).\n\n"
        ."## المادة الثالثة والخمسون بعد المائة:\nعلى صاحب العمل توفير الرعاية الطبية."
    )->and($page['english'])->toBe(
        "## Article 149:\n(Repealed)\n\n[^53]: Repealed by Royal Decree No. (M/5) dated 1442/1/7H.\n\n"
        ."## Article 151:\n1. A female worker is entitled to maternity leave.\n\n[^55]: Amended and merged with Article 152, and amended by Royal Decree No. (M/44).\n\n"
        ."## Article 153:\nThe employer must provide medical care."
    );
});

it('reads a mark left after a label\'s colon with a full stop, and keeps a note it cannot place out of every article', function () {
    $original = "المادة العاشرة بعد المائتين: 76.\n(ملغاة)\n\nالمادة الحادية عشرة بعد المائتين: 77.\n(ملغاة)\n\n"
        ."-76 ألغيت بالمرسوم الملكي رقم (م/1) وتاريخ 1435/1/22هـ.\n-77 ألغيت بالمرسوم الملكي رقم (م/1) وتاريخ 1435/1/22هـ.\n-78 ألغيت بالمرسوم الملكي رقم (م/1) وتاريخ 1435/1/22هـ.";
    $english = "Article 210: 76.\n(Repealed)\n\nArticle 211: 77.\n(Repealed)\n\n"
        ."-76 Repealed by Royal Decree No. (M/1) dated 1435/1/22H.\n-77 Repealed by Royal Decree No. (M/1) dated 1435/1/22H.\n-78 Repealed by Royal Decree No. (M/1) dated 1435/1/22H.";

    $page = PdfFootnotes::apply($original, $english, [76 => '', 77 => 'المادة الحادية عشرة بعد المائتين: 77.', 78 => '']);

    expect($page['original'])->toBe(
        "المادة العاشرة بعد المائتين:\n(ملغاة)\n\n[^76]: ألغيت بالمرسوم الملكي رقم (م/1) وتاريخ 1435/1/22هـ.\n\n"
        ."المادة الحادية عشرة بعد المائتين:\n(ملغاة)\n\n[^77]: ألغيت بالمرسوم الملكي رقم (م/1) وتاريخ 1435/1/22هـ.\n\n## الحواشي\n[^78]: ألغيت بالمرسوم الملكي رقم (م/1) وتاريخ 1435/1/22هـ."
    )->and($page['english'])->toBe(
        "Article 210:\n(Repealed)\n\n[^76]: Repealed by Royal Decree No. (M/1) dated 1435/1/22H.\n\n"
        ."Article 211:\n(Repealed)\n\n[^77]: Repealed by Royal Decree No. (M/1) dated 1435/1/22H.\n\n## Footnotes\n[^78]: Repealed by Royal Decree No. (M/1) dated 1435/1/22H."
    );
});

it('takes a mark left on its own line under a heading, and leaves an article\'s first clause alone', function () {
    $page = PdfFootnotes::apply(
        "المادة الحادية عشرة بعد المائتين:\n77.\n(ملغاة)\n\nالمادة السادسة:\n5- يسري هذا النظام على كل عقد.\n\n5- عدلت بالمرسوم الملكي رقم (م/46).\n77- ألغيت بالمرسوم الملكي رقم (م/1).",
        "Article 211:\n77.\n(Repealed)\n\nArticle 6:\n5- This law applies to every contract.\n\n5- Amended by Royal Decree No. (M/46).\n77- Repealed by Royal Decree No. (M/1).",
        [5 => 'المادة السادسة:', 77 => 'المادة الحادية عشرة بعد المائتين:'],
    );

    expect($page['original'])->toBe("المادة الحادية عشرة بعد المائتين:\n(ملغاة)\n\n[^77]: ألغيت بالمرسوم الملكي رقم (م/1).\n\nالمادة السادسة:\n5- يسري هذا النظام على كل عقد.\n\n[^5]: عدلت بالمرسوم الملكي رقم (م/46).")
        ->and($page['english'])->toBe("Article 211:\n(Repealed)\n\n[^77]: Repealed by Royal Decree No. (M/1).\n\nArticle 6:\n5- This law applies to every contract.\n\n[^5]: Amended by Royal Decree No. (M/46).");
});

it('never takes the model\'s word for a heading whose article number the footnote\'s echoes', function () {
    // Page 44 of the labor law: footnote 56 is on Article 152, but asked, the model
    // named Article 156. With no mark written beside the heading, the note is kept
    // out of every article rather than put under a wrong one.
    $page = PdfFootnotes::apply(
        "## المادة الحادية والخمسون بعد المائة:\nللمرأة العاملة الحق في إجازة وضع.\n\n## المادة الثانية والخمسون بعد المائة:\n(ملغاة)\n\n## المادة السادسة والخمسون بعد المائة:\n(ملغاة)\n\n"
            ."55- عدلت ودمج بها حكم المادة الثانية والخمسون بعد المائة.\n56- عدلت وألغيت بعد دمج حكمها مع المادة الحادية والخمسون بعد المائة.",
        "## Article 151:\nA female worker is entitled to maternity leave.\n\n## Article 152:\n(Repealed)\n\n## Article 156:\n(Repealed)\n\n"
            ."55- Amended and merged with Article 152.\n56- Amended and repealed after merging with Article 151.",
        [55 => 'المادة الحادية والخمسون بعد المائة:', 56 => 'المادة السادسة والخمسون بعد المائة:'],
    );

    expect($page['original'])->toBe(
        "## المادة الحادية والخمسون بعد المائة:\nللمرأة العاملة الحق في إجازة وضع.\n\n[^55]: عدلت ودمج بها حكم المادة الثانية والخمسون بعد المائة.\n\n"
        ."## المادة الثانية والخمسون بعد المائة:\n(ملغاة)\n\n## المادة السادسة والخمسون بعد المائة:\n(ملغاة)\n\n## الحواشي\n[^56]: عدلت وألغيت بعد دمج حكمها مع المادة الحادية والخمسون بعد المائة."
    )->and($page['english'])->toBe(
        "## Article 151:\nA female worker is entitled to maternity leave.\n\n[^55]: Amended and merged with Article 152.\n\n"
        ."## Article 152:\n(Repealed)\n\n## Article 156:\n(Repealed)\n\n## Footnotes\n[^56]: Amended and repealed after merging with Article 151."
    );
});

it('leaves a page without footnote marks exactly as it is', function () {
    expect(PdfFootnotes::apply("## Article 1:\n1- Text 1446/2/8.", "## Article 1:\n1- Text 1446/2/8.", []))
        ->toBe(['original' => "## Article 1:\n1- Text 1446/2/8.", 'english' => "## Article 1:\n1- Text 1446/2/8."]);
});
