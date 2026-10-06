<?php

use App\Models\Employee;
use App\Models\User;
use App\Services\Ai\AssistantToolbox;
use App\Services\Ai\KnowledgeRetriever;
use App\Services\Ticketing\TicketRequestService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The assistant finding a person by their name, whichever script and
 * whichever spelling the employee used.
 *
 * What is defended: a name asked for in Arabic finds the person — by their
 * Arabic name where the record has one, by how the name sounds where it has
 * not — an English name spelled another way finds them too, a name as
 * written still beats a name that only sounds alike, and the looser match
 * says that it is one.
 */
uses(Tests\TestCase::class);

beforeEach(function () {
    config(['cache.default' => 'array']);

    foreach (['employees', 'departments', 'branches'] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('branches', function (Blueprint $t) {
        $t->unsignedInteger('id')->primary();
        $t->string('name');
        $t->string('city')->nullable();
        $t->timestamps();
    });
    Schema::create('departments', function (Blueprint $t) {
        $t->unsignedInteger('id')->primary();
        $t->string('name');
        $t->timestamps();
    });
    Schema::create('employees', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('name_ar')->nullable();
        $t->string('email')->nullable();
        $t->string('oracle_emp_no')->nullable();
        $t->string('job_title')->nullable();
        $t->string('work_phone')->nullable();
        $t->string('mobile_phone')->nullable();
        $t->string('extension_number')->nullable();
        $t->unsignedInteger('branch_id')->nullable();
        $t->unsignedInteger('department_id')->nullable();
        $t->unsignedBigInteger('linked_primary_employee_id')->nullable();
        $t->string('status')->default('active');
        $t->timestamps();
    });

    DB::table('branches')->insert([['id' => 1, 'name' => 'JED', 'city' => 'Jeddah'], ['id' => 2, 'name' => 'CAI', 'city' => 'Cairo']]);
    DB::table('departments')->insert(['id' => 1, 'name' => 'Information Systems']);
});

/** The directory: Saudi staff with the Arabic name Oracle sends, Cairo staff with none. */
function nameDirectory(): void
{
    $person = fn (string $name, ?string $arabic, string $email, int $branch, array $more = []) => array_merge([
        'name' => $name, 'name_ar' => $arabic, 'email' => $email, 'branch_id' => $branch, 'department_id' => 1,
        'status' => 'active', 'oracle_emp_no' => null, 'extension_number' => null, 'mobile_phone' => null,
    ], $more);

    Employee::insert([
        $person('Mohammad Salameh', 'محمد سلامه', 'mohammad.salameh@samirgroup.com', 1, ['extension_number' => '2210', 'oracle_emp_no' => '1500']),
        $person('Taghreed Khalawi', 'تغريد احمد خلاوي', 'taghreed.khalawi@samirgroup.com', 1, ['oracle_emp_no' => '2574']),
        $person('Mahmoud Alharbi', 'محمود الحربي', 'mahmoud.alharbi@samirgroup.com', 1),
        $person('Abdulrahman Alghamdi', 'عبدالرحمن الغامدي', 'abdulrahman.alghamdi@samirgroup.com', 1),
        // SSS Egypt: outside Oracle's feed, so no Arabic name on record.
        $person('Mohamed Zahran', null, 'mohamed.zahran@sssegypt.com', 2, ['mobile_phone' => '+201001234567']),
        $person('Mohammed Ibrahim', null, 'mohammed.ibrahim@sssegypt.com', 2),
        $person('Ahmed Mostafa', null, 'ahmed.mostafa@sssegypt.com', 2),
        $person('Hamed Fathy', null, 'hamed.fathy@sssegypt.com', 2),
        $person('Youssef Gamal', null, 'youssef.gamal@sssegypt.com', 2),
        $person('Left Person', 'محمد زهران', 'left.person@sssegypt.com', 2, ['status' => 'terminated']),
    ]);
}

function nameToolbox(): AssistantToolbox
{
    return new AssistantToolbox(
        new User(['name' => 'Asker', 'email' => 'asker@samirgroup.com']),
        null,
        null,
        app(KnowledgeRetriever::class),
        app(TicketRequestService::class),
    );
}

/** @return list<string> the names lookup_colleague hands back */
function lookedUp(string $query, ?string $branch = null): array
{
    return array_column(nameToolbox()->call('lookup_colleague', array_filter(['query' => $query, 'branch' => $branch]))['colleagues'], 'name');
}

/** The toolbox's own person matching, over everybody in the directory. */
function matchedNames(string $query): array
{
    $toolbox = nameToolbox();
    $match = Closure::bind(fn (Collection $people, string $q) => $this->matchPeople($people, $q), $toolbox, AssistantToolbox::class);

    return $match(Employee::where('status', 'active')->orderBy('name')->get(), $query)->pluck('name')->all();
}

// ─── The directory lookup ─────────────────────────────────────────

it('finds a colleague by their Arabic name', function () {
    nameDirectory();

    // Until 2026-10-06 the lookup read the English name only: every one of
    // these found nobody.
    expect(lookedUp('تغريد'))->toBe(['Taghreed Khalawi'])
        ->and(lookedUp('تغريد خلاوي'))->toBe(['Taghreed Khalawi'])
        ->and(lookedUp('محمد سلامة'))->toBe(['Mohammad Salameh'])
        ->and(lookedUp('محمود الحربي'))->toBe(['Mahmoud Alharbi']);
});

it('finds somebody with no Arabic name on record by how their name sounds', function () {
    nameDirectory();

    expect(lookedUp('محمد زهران'))->toBe(['Mohamed Zahran'])
        ->and(lookedUp('يوسف جمال'))->toBe(['Youssef Gamal'])
        ->and(lookedUp('أحمد مصطفى'))->toBe(['Ahmed Mostafa'])
        // Ahmed is not Hamed.
        ->and(lookedUp('حامد'))->toBe(['Hamed Fathy']);
});

it('finds every Mohamed for the Arabic name, however each is spelled, and not Mahmoud', function () {
    nameDirectory();

    // The one whose Arabic name is on record first, then the ones found by sound.
    expect(lookedUp('محمد'))->toBe(['Mohammad Salameh', 'Mohamed Zahran', 'Mohammed Ibrahim'])
        ->and(lookedUp('محمود'))->toBe(['Mahmoud Alharbi']);
});

it('finds an English name spelled another way', function () {
    nameDirectory();

    // The model re-spells an Arabic name in English its own way.
    expect(lookedUp('Mohamed Salama'))->toBe(['Mohammad Salameh'])
        ->and(lookedUp('Muhammad Zahran'))->toBe(['Mohamed Zahran'])
        ->and(lookedUp('Yousef Jamal'))->toBe(['Youssef Gamal'])
        ->and(lookedUp('Abdel Rahman Al Ghamdi'))->toBe(['Abdulrahman Alghamdi']);
});

it('says when it matched by sound, and not when the name was there as written', function () {
    nameDirectory();
    $toolbox = nameToolbox();

    $written = $toolbox->call('lookup_colleague', ['query' => 'Mohamed Zahran']);
    $sounded = $toolbox->call('lookup_colleague', ['query' => 'Muhammad Zahran']);

    expect($written)->not->toHaveKey('note')
        ->and($sounded['note'])->toContain('by how it sounds')
        // The Arabic name goes back with the English, where there is one.
        ->and($toolbox->call('lookup_colleague', ['query' => 'Taghreed'])['colleagues'][0])
        ->toMatchArray(['name' => 'Taghreed Khalawi', 'name_arabic' => 'تغريد احمد خلاوي']);
});

it('prefers the name as written to a name that only sounds alike', function () {
    nameDirectory();

    // "Mohamed" is somebody's name as written, so the other spellings are
    // not offered for it: asked in English, the employee chose a spelling.
    expect(lookedUp('Mohamed'))->toBe(['Mohamed Zahran'])
        ->and(lookedUp('mohammad.salameh'))->toBe(['Mohammad Salameh']);
});

it('still looks up by email, phone, extension and branch', function () {
    nameDirectory();

    expect(lookedUp('2210'))->toBe(['Mohammad Salameh'])
        ->and(lookedUp('1001234567'))->toBe(['Mohamed Zahran'])
        ->and(lookedUp('zahran@sssegypt'))->toBe(['Mohamed Zahran'])
        ->and(lookedUp('محمد', 'Cairo'))->toBe(['Mohamed Zahran', 'Mohammed Ibrahim'])
        ->and(lookedUp('محمد', 'JED'))->toBe(['Mohammad Salameh']);
});

it('never finds somebody who has left, and says so plainly when nobody matches', function () {
    nameDirectory();

    expect(lookedUp('Left Person'))->toBe([])
        ->and(nameToolbox()->call('lookup_colleague', ['query' => 'سلطان القرني']))
        ->toBe(['colleagues' => [], 'message' => 'No matching colleague found in the directory.']);
});

it('puts the people whose own first name it is ahead of the people whose father\'s it is', function () {
    // Oracle's Arabic name is the full one, so the name asked for is in far
    // more names than it is the first name of.
    Employee::insert([
        ['name' => 'Ali Hassan', 'name_ar' => 'علي محمد حسن', 'email' => 'ali.hassan@samirgroup.com', 'branch_id' => 1, 'status' => 'active'],
        ['name' => 'Bader Alharbi', 'name_ar' => 'بدر محمد الحربي', 'email' => 'bader.alharbi@samirgroup.com', 'branch_id' => 1, 'status' => 'active'],
        ['name' => 'Mohammed Saleh', 'name_ar' => 'محمد صالح علي', 'email' => 'mohammed.saleh@samirgroup.com', 'branch_id' => 1, 'status' => 'active'],
        ['name' => 'Zaid Mohamed', 'name_ar' => null, 'email' => 'zaid.mohamed@sssegypt.com', 'branch_id' => 2, 'status' => 'active'],
        ['name' => 'Mohamed Zahran', 'name_ar' => null, 'email' => 'mohamed.zahran@sssegypt.com', 'branch_id' => 2, 'status' => 'active'],
    ]);

    $found = lookedUp('محمد');

    expect(array_slice($found, 0, 2))->toEqualCanonicalizing(['Mohammed Saleh', 'Mohamed Zahran'])
        ->and(array_slice($found, 2))->toEqualCanonicalizing(['Ali Hassan', 'Bader Alharbi', 'Zaid Mohamed']);
});

it('stops at ten people and says there are more', function () {
    $rows = [];
    for ($i = 1; $i <= 14; $i++) {
        $rows[] = ['name' => "Mohammed Person {$i}", 'name_ar' => null, 'email' => "m{$i}@sssegypt.com", 'branch_id' => 2, 'status' => 'active'];
    }
    Employee::insert($rows);

    $answer = nameToolbox()->call('lookup_colleague', ['query' => 'محمد']);

    expect($answer['colleagues'])->toHaveCount(10)
        ->and($answer['more_not_shown'])->toBe(4)
        ->and($answer['note'])->toContain('narrow it down');
});

// ─── The matching the team tools share ────────────────────────────

it('matches a team member by Arabic name and by sound, the same way', function () {
    nameDirectory();

    // get_team_member_attendance and the leave tools go through this.
    expect(matchedNames('تغريد'))->toBe(['Taghreed Khalawi'])
        ->and(matchedNames('محمد زهران'))->toBe(['Mohamed Zahran'])
        ->and(matchedNames('Mohamed Salama'))->toBe(['Mohammad Salameh'])
        ->and(matchedNames('عبد الرحمن الغامدي'))->toBe(['Abdulrahman Alghamdi'])
        // An employee number and an email are still exact.
        ->and(matchedNames('2574'))->toBe(['Taghreed Khalawi'])
        ->and(matchedNames('ahmed.mostafa@sssegypt.com'))->toBe(['Ahmed Mostafa'])
        ->and(matchedNames('سلطان'))->toBe([]);
});

it('tells the model to pass a name as the employee wrote it', function () {
    $tools = collect(nameToolbox()->definitions())->keyBy('function.name');

    expect($tools['lookup_colleague']['function']['parameters']['properties']->query['description'])->toContain('never translate or re-spell a name')
        ->and($tools['get_team_member_attendance']['function']['parameters']['properties']->member['description'])->toContain('in English or Arabic');
});
