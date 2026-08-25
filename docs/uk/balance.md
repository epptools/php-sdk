# Баланс і ціни

На цій сторінці живуть два механізми. Вони незалежні один від одного і мають одне спільне: обидва
мають справу з грошима, а гроші в цій бібліотеці — це завжди точний десятковий **рядок**.

- **Запит балансу** — скільки тримає ваш обліковий запис і скільки він ще може витратити. Він іде у
  власному розширенні балансу реєстру, і його простір імен клієнт читає з `<greeting>`. Ця команда не
  описана в жодному RFC, тож реєстр може її й не пропонувати: там, де не пропонує, `balance()` кидає
  `ConfigException` з переліком того, що сервер оголосив, а не надсилає кадр, який той проігнорує.
  Див. [Команди](commands.md#власні-розширення-вашого-реєстру).
- **Розширення fee (RFC 8748)** — стандартний EPP. Запитайте, скільки коштувала б операція, і
  обмежте суму, яку команда має право з вас списати.

Усе тут припускає підключений клієнт із виконаним входом — див. [Сесія](session.md).

**Гроші повертаються точним десятковим рядком, ніколи не float.** У двійковій рухомій комі
`0.1 + 0.2` — це не `0.3`, і баланс, підсумований у такий спосіб, дрейфує на сотку тут і на сотку
там, доки ваша бухгалтерія й бухгалтерія реєстру не розійдуться. Використовуйте `bcmath` або цілі
числа в найменших одиницях валюти. Те саме правило діє для кожної суми на цій сторінці: для
котированої ціни, для межі, для стягнутої комісії, для кредитної лінії.

Суми в прикладах наведено для ілюстрації. Це не тариф реєстру.

## Поверхня API

| Виклик | Надсилає | Відповідає |
|---|---|---|
| `$client->balance(): Response` | `<info><balance:info>` | кредитна лінія, баланс, доступні кошти |
| `domain()->check(array $names, array $fee = [], ?string $currency = null): Response` | `domain:check` + `<fee:check>` | доступність **і** ціни |
| `domain()->create($name, ['fee' => …])` | `domain:create` + `<fee:create>` | create з погодженою межею |
| `domain()->renew($name, $curExpDate, $years, $fee)` | `domain:renew` + `<fee:renew>` | продовження з погодженою межею |
| `domain()->transfer('request', $name, $authInfo, $years, $fee)` | `domain:transfer` + `<fee:transfer>` | трансфер з погодженою межею |
| `domain()->restore($name, $fee)` | `domain:update` + `<fee:update>` | відновлення з погодженою межею |

| Аксесор | Що читає |
|---|---|
| `balance(): ?array` | увесь блок балансу, або `null` |
| `creditLimit(): ?string` · `currentBalance(): ?string` · `availableCredit(): ?string` | по одному числу кожен |
| `fees(): array` | кожне котирування у відповіді на check, за іменем |
| `feeFor(string $name, string $operation, int $years = 1): ?string` | одне котирування |
| `feeClass(?string $name = null): ?string` · `isPremium(?string $name = null): bool` | до якого прайс-листа належить ім'я |
| `chargedFee(): ?array` · `feeAmount(): ?string` · `feeCurrency(): ?string` | що команда справді стягнула |

---

## balance

```php
public function balance(): Response
```

**У каналі передачі:** `<command><info><balance:info/></info>` у просторі імен balance реєстру
(`http://registry.example/epp/balance-1.0`). Це читання: нічого не списується і нічого не змінюється.

Він висить на самому клієнті, а не на обробнику команд об'єкта, бо стосується облікового запису, а
не об'єкта.

```php
$b = $client->balance();

$b->creditLimit();       // '5000.00'  — наскільки нижче нуля може опуститися рахунок
$b->currentBalance();    // '1240.50'  — скільки на ньому зараз
$b->availableCredit();   // '6240.50'  — скільки ви ще можете витратити: баланс плюс лінія
```

Усі три — десяткові рядки у валюті вашого облікового запису. Увесь блок одним викликом:

```php
$b->balance();
// ['creditLimit' => '5000.00', 'balance' => '1240.50', 'availableCredit' => '6240.50']
```

`currentBalance()` існує тому, що `balance()` — це блок, а `balance` — одне число всередині нього.
`$b->balance()['balance']` дає те саме значення; іменований аксесор потрібен, щоб рядок про гроші не
читався як описка.

**`balance()` повертає `null`, коли у відповіді немає блоку балансу.** Перевіряйте саме це, а не
припускайте, що числа на місці:

```php
$b = $client->balance();

if ($b->balance() === null) {
    // Це не відповідь про баланс. Із throwOnFailure(false) відмова приходить сюди як звичайний
    // Response замість винятку, і жодних чисел вона не несе.
    throw new RuntimeException($b->message() ?? 'no balance in the reply');
}
```

### Перевірка перед пакетом операцій

Викликати його варто для того, щоб щось вирішити до витрат. Порівнюйте через `bcmath`, а не
оператором `<` над float:

```php
$available = (string) $client->balance()->availableCredit();
$needed    = '2400.00';                     // 24 реєстрації по 100.00 для ілюстрації

if (bccomp($available, $needed, 2) < 0) {
    // Зупиніться тут, а не на 13-му імені, посеред пакета.
    alertBilling("available {$available}, need {$needed}");
    return;
}
```

Пакет, у якого кошти вичерпалися посеред роботи, — не катастрофа: реєстр відхиляє кожну наступну
платну команду з `2104` і не стягує нічого, — але вам лишається звіряти напівзроблене замовлення.
Чому `2104` означає «зупинити пакет», а не «пропустити ім'я», див. у
[Помилки](errors.md#insufficientfundsexception-2104).

### Сповіщення про низький баланс

Реєстр може й сам надіслати вам ці числа. [poll-сповіщення](poll.md#сповіщення-про-низький-баланс) про
низький баланс несе той самий блок, тож читають його ті самі аксесори:

```php
$client->poll()->drain(function (EppTools\Response $notice): void {
    if ($notice->balance() !== null) {
        alertBilling((string) $notice->currentBalance());   // десятковий рядок — ніколи не приводьте
    }
});
```

**Коди відповіді:** `1000`, із числами в кадрі. Відмова — служба не пропонується цій сесії,
обліковому запису не дозволено її читати — надходить як `CommandException`, як і будь-яка інша;
якщо ви її бачите, перевірте, чи URI балансу було оголошено під час входу. Типово вхід оголошує
рівно ті служби, які запропонувало привітання, тож зазвичай річ у тому, що `Config::$extUris`
називає власний список, — див. [Сесія](session.md).

---

## Ціни: розширення fee (RFC 8748)

Одне розширення, два цілком окремі застосування. Тримайте їх у голові окремо — і решта складеться
сама:

| Застосування | Де | Що робить |
|---|---|---|
| **Запитати** | `domain()->check()` | котирує ціну. Нічого не змінює, нічого не коштує |
| **Обмежити** | `create`, `renew`, `transfer`, `restore` | вказує максимум, на який ви погоджуєтеся. Вища реальна ціна веде до відмови в команді |

Межа — це **не** ціна, яку встановлюєте ви. Реєстр стягує власний тариф. Межа дає вам те, що тариф
не може перевищити погоджене вами без того, щоб команда зазнала невдачі замість того, щоб виставити
вам рахунок.

Обидва застосування необов'язкові. Команда без блоку fee виконується як звичайно, і стягується
власна ціна реєстру.

---

### Запит ціни під час check

```php
public function check(array $names, array $fee = [], ?string $currency = null): Response
```

`$fee` — це `operation => years`. Операції: `create`, `renew`, `transfer`, `restore`, `update` та
`delete`.

```php
$r = $client->domain()->check(['example.com.ua'], fee: ['create' => 1, 'renew' => 1]);

$r->isAvailable('example.com.ua');                  // true — ім'я вільне
$r->feeFor('example.com.ua', 'create', 1);          // '100.00'
$r->feeFor('example.com.ua', 'renew', 1);           // '90.00'
$r->fees()['_currency'];                            // 'UAH'
```

Один обмін із сервером відповідає на обидва питання — чи вільне ім'я і скільки воно коштувало б.
Блок комісій застосовується до кожного імені в команді, а у відповіді на кожне ім'я припадає один
запис:

```php
$r = $client->domain()->check(
    ['one.com.ua', 'two.com.ua', 'three.com.ua'],
    fee: ['create' => 1],
);

foreach ($r->availability() as $name => $free) {
    if ($free) {
        printf("%-16s %s %s\n", $name, $r->feeFor($name, 'create', 1) ?? '-', $r->fees()['_currency']);
    }
}
```

Відповідь про доступність — це знімок, і ціна теж. Між check і create ім'я можуть зайняти, а тариф —
змінитися; саме для цього й потрібна [межа](#обмеження-суми-на-яку-ви-погоджуєтеся).

---

### Кілька періодів в одній команді

**Список** років запитує ту саму операцію на кожному з періодів, тож ціла таблиця цін коштує одного
обміну замість п'яти:

```php
$table = $client->domain()->check(['example.com.ua'], fee: ['create' => [1, 2, 3, 5, 10]], currency: 'UAH');

$table->feeFor('example.com.ua', 'create', 1);    // '100.00'
$table->feeFor('example.com.ua', 'create', 5);    // '480.00'
$table->feeFor('example.com.ua', 'create', 10);   // '950.00'
```

Скаляри і списки вільно поєднуються, і кілька операцій можуть їхати разом:

```php
$client->domain()->check(['example.com.ua'], fee: [
    'create'  => [1, 2, 5],
    'renew'   => [1, 2],
    'restore' => 1,
]);
```

**Кадр несе щонайбільше 20 записів комісій.** Запис — це одна пара *(операція, період)*, тож у
прикладі вище їх шість: три create, два renew, один restore. Кількість імен тут ні до чого —
двадцять записів лишаються двадцятьма, питаєте ви про одне ім'я чи про п'ятдесят.

```php
$client->domain()->check(['example.com.ua'], fee: ['create' => range(1, 30)]);
// ValidationException: a fee query carries at most 20 entries; this one has 30
```

Це відхиляється тут, ще до побудови кадру, а не в реєстрі, де надто довгий запит повертається
`2306`, який не називає нічого конкретного. Розділіть його на два виклики.

Період, менший за один, надсилається як один, тож `0` питає ціну на рік, а не ціну ні за що.

---

### Вибір валюти

```php
$r = $client->domain()->check(['example.com.ua'], fee: ['create' => 1], currency: 'UAH');
```

Валюта їде як `<fee:currency>` і переводиться у верхній регістр за вас. Пропустіть її — і реєстр
котируватиме у своїй.

**Валюта, у якій реєстр не котирує, повертається як недоступна, з причиною, а не як перерахований
здогад.** У цій різниці й суть: перерахована сума виглядала б як котирування, від якого можна
відштовхнутися, задаючи межу, — і була б хибною.

```php
$r = $client->domain()->check(['example.com.ua'], fee: ['create' => 1], currency: 'JPY');

$entry = $r->fees()['example.com.ua'] ?? null;
if ($entry !== null && $entry['avail'] === false) {
    echo $entry['reason'], "\n";     // напр. 'Currency not supported'
}
```

Передана валюта без жодної операції надсилає `<fee:check>`, який несе саму лише валюту. Називайте
натомість ті операції, котирування яких вам справді потрібні: відповідь на запит, що не питає
нічого, — це політика реєстру, а не те, на що можна покластися.

---

### transfer і restore — операції на один рік

**Скільки б років ви не питали, `transfer` і `restore` котируються як один рік**, а відповідь
повторює той період, який справді буде стягнуто. Тож питайте їх на один рік і зчитуйте на один рік:

```php
$r = $client->domain()->check(['example.com.ua'], fee: ['transfer' => 1, 'restore' => 1]);

$r->feeFor('example.com.ua', 'transfer', 1);   // '120.00'
$r->feeFor('example.com.ua', 'restore', 1);    // '1000.00'
$r->feeFor('example.com.ua', 'restore', 3);    // null — на три роки нічого не котирувалося
```

Запит `['restore' => 3]` не є помилкою; відповідь повертається з описом одного року, бо саме такою
є ця операція. Зчитування на трьох роках дає `null`, а `null`, сприйнятий як «безплатно», — це те,
як відновлення проводять у книгах за нуль. Прочитайте список `periods`, якщо хочете побачити період,
який реєстр справді оцінив:

```php
foreach ($r->fees()['example.com.ua']['periods'] as $quote) {
    // ['op' => 'restore', 'years' => 1, 'fee' => '1000.00'] — years — це те, що ОЦІНЕНО
}
```

Те саме стосується трансферу, який несе обов'язкове продовження: продовження — окремий рядок
каталогу, тож питайте `transfer` і `renew` разом, якщо хочете суму.

---

### Читання відповіді

```php
public function fees(): array
public function feeFor(string $name, string $operation, int $years = 1): ?string
public function feeClass(?string $name = null): ?string
public function isPremium(?string $name = null): bool
```

`fees()` — це вся відповідь, за ключем-іменем, і валюта поруч:

```php
$r = $client->domain()->check(['example.com.ua'], fee: ['create' => [1, 2, 5]], currency: 'UAH');

$r->fees();
// [
//   '_currency'      => 'UAH',
//   'example.com.ua' => [
//       'avail'    => true,              // чи зміг реєстр ОЦІНИТИ його — див. нижче
//       'reason'   => null,              // чому ні, коли avail дорівнює false
//       'class'    => 'premium',         // присутній, лише коли реєстр надіслав клас
//       'commands' => ['create' => ['years' => 1, 'fee' => '100.00']],
//       'periods'  => [
//           ['op' => 'create', 'years' => 1, 'fee' => '100.00'],
//           ['op' => 'create', 'years' => 2, 'fee' => '200.00'],
//           ['op' => 'create', 'years' => 5, 'fee' => '480.00'],
//       ],
//   ],
// ]
```

Три речі, які варто знати про цю форму:

- **`commands` містить один запис на операцію** — той перший період, який ви запитали. Коли ви
  питали кілька періодів, читайте `feeFor()` або `periods`. Цикл по `commands` після запиту
  `[1, 2, 5]` тихо повідомить річну ціну для всіх трьох.
- **`avail` тут про оцінювання, а не про ім'я.** `false` означає, що реєстр не зміг його котирувати
  — зона, якої він не обслуговує, валюта, у якій він не котирує, — а `reason` каже, що саме. Чи
  вільне *ім'я* — це `isAvailable()`, інше питання з іншою відповіддю.
- **Котирування може бути `null` усередині періоду**, а поруч стоятиме `reason`: реєстр оцінив ім'я,
  але не цю операцію. `null` — це «немає котирування», ніколи не «безплатно».

```php
$fee = $r->feeFor('example.com.ua', 'create', 1);
if ($fee === null) {
    throw new RuntimeException('no create quote for example.com.ua — do not assume a price');
}
```

`feeClass()` та `isPremium()` кажуть, до якого прайс-листа належить ім'я:

```php
$r->feeClass('example.com.ua');    // 'premium' | 'standard' | null
$r->isPremium('example.com.ua');   // true, коли клас присутній і не дорівнює 'standard'
```

Обидва приймають необов'язкове ім'я; без нього вони відповідають про перше ім'я у відповіді, яке
несе клас. **Беріть ціну з `fees()`, а не з класу.** Клас каже, який прайс-лист застосовано, а не
скільки це коштує, і `false` від `isPremium()` означає лише «у відповіді не оголошено особливого
класу», а не обіцянку стандартної ціни.

Інша річ зі схожою назвою: `prices()` та `priceChannel()` у `domain:info` — це власні цінові
підказки реєстру для домену, який ви вже тримаєте, а не котирування з RFC 8748. Вони описані в
[Відповіді](responses.md#домен).

---

## Обмеження суми, на яку ви погоджуєтеся
Те саме розширення, спрямоване в інший бік. У команді, що змінює дані, ви вказуєте максимум, який
згодні заплатити, і реєстр радше відхилить команду, ніж спише більше.

| Команда | Як передається межа | Канал передачі |
|---|---|---|
| `create` | опцією `'fee'` | `<fee:create>` |
| `renew` | четвертим аргументом | `<fee:renew>` |
| `transfer` | п'ятим аргументом (у `request`) | `<fee:transfer>` |
| `restore` | другим аргументом | `<fee:update>` — відновлення *і є* оновленням |
| `update` | опцією `'fee'` | `<fee:update>` |

Дві форми, скрізь однакові:

```php
'fee' => '100.00',                                     // сума, у власній валюті реєстру
'fee' => ['amount' => '100.00', 'currency' => 'UAH'],  // …і валюта, у якій вона задана
```

```php
// Create
$client->domain()->create('example.com.ua', [
    'years'      => 1,
    'registrant' => 'C-0001',
    'fee'        => '100.00',
]);

// Renew
$client->domain()->renew('example.com.ua', '2027-04-01', 1, ['amount' => '90.00', 'currency' => 'UAH']);

// Вхідний трансфер
$client->domain()->transfer('request', 'example.com.ua', 'the-code', 1, '120.00');

// Restore
$client->domain()->restore('example.com.ua', '1000.00');
```

[Білдери](builders.md) називають це `maxFee()` — саме тим, чим воно є:

```php
$client->domain()->createBuilder('example.com.ua')
    ->years(1)
    ->registrant('C-0001')
    ->maxFee('100.00', 'UAH')
    ->send();
```

`maxFee()` ще й перевіряє, що сума — звичайне десяткове число (`100`, `100.5`, `100.00`), і кидає
`ValidationException` ще до надсилання, якщо це не так. Передана напряму опція віддає ваш рядок
реєстру як є, і некоректний повертається голим `2004`/`2005`, який не називає жодного поля, — уже
після спроби виконати команду.

### Що означає відмова з 2004

**`2004` на команді, яка несла межу, означає, що реальна ціна вища за цю межу. Нічого не зроблено і
нічого не стягнуто.**

```php
use EppTools\Exception\CommandException;
use EppTools\ResultCode;

try {
    $client->domain()->create('rare.com.ua', [
        'years'      => 1,
        'registrant' => 'C-0001',
        'fee'        => '100.00',
    ]);
} catch (CommandException $e) {
    if ($e->eppCode === ResultCode::PARAMETER_VALUE_RANGE_ERROR) {
        // Домен НЕ зареєстровано, і з вас НЕ стягнуто. Перезапитайте ціну і вирішуйте заново —
        // не розширюйте межу в циклі, доки не пройде: саме так преміальне ім'я купують за
        // ціну, якої ніхто не погоджував.
        $quote = $client->domain()->check(['rare.com.ua'], fee: ['create' => 1])
                        ->feeFor('rare.com.ua', 'create', 1);
        askAHumanAbout('rare.com.ua', $quote);
    }
}
```

У цьому й уся цінність межі: зміна тарифу, преміальне ім'я, про преміальність якого ви не знали, чи
застаріла ціна у вашому власному кеші перетворюються на відмову, яку можна побачити, а не на
рахунок, який ви знайдете згодом. `2004` — це ще й загальний код «значення поза діапазоном», тож він
може означати період, якого зона не пропонує; що саме — скаже `reasons()` у винятку, а межа — це
перше, що варто перевірити, коли команда її несла.

Автоматичне розширення межі зводить її нанівець. Якщо create завершився `2004`, перезапитайте ціну
через `check()` і або свідомо погодьтеся на нову ціну, або лишіть ім'я в спокої.

---

## Читання того, що команда справді стягнула

```php
public function chargedFee(): ?array
public function feeAmount(): ?string
public function feeCurrency(): ?string
```

Успішна команда, яка несла погодження щодо комісії, повторює у відповіді те, що стягнула:

```php
$r = $client->domain()->create('example.com.ua', [
    'years'      => 1,
    'registrant' => 'C-0001',
    'fee'        => '100.00',
]);

$r->chargedFee();     // ['currency' => 'UAH', 'fee' => '100.00']
$r->feeAmount();      // '100.00'
$r->feeCurrency();    // 'UAH'
```

**Записуйте до замовлення `feeAmount()`, а не те число, яке ви отримали в котируванні через
`check`.** Котирування було твердженням про мить; а це — те, що реєстр виставив. Майже завжди вони
збігаються, і весь сенс зберігати друге — саме той випадок, коли ні.

`null` означає, що у відповіді не було блоку комісії — звичайна відповідь для команди, надісланої
без межі, до реєстру, який не повторює цін, коли його про них не питали. Це ніколи не означає
«безплатно».

Це значення читається з того блоку, який несе відповідь (`creData`, `renData`, `trnData`, `updData`,
`delData`), тож ті самі три аксесори працюють після create, renew, transfer, restore і delete.

---

## Реєстрація з перевіреною ціною, від початку до кінця

```php
use EppTools\Client;
use EppTools\Config;
use EppTools\Exception\CommandException;
use EppTools\Exception\EppException;
use EppTools\Exception\InsufficientFundsException;
use EppTools\ResultCode;

$client = new Client(Config::fromArray([
    'host'     => 'epp.registry.example',
    'clid'     => 'EXAMPLE',
    'password' => 'your-secret',
    'caFile'   => '/path/to/registry-ca.pem',
]));

try {
    $client->connect();
    $client->login();

    $name = 'example.com.ua';

    // 1. Чи є взагалі гроші, щоб це зробити?
    $available = (string) $client->balance()->availableCredit();

    // 2. Запитайте доступність і ціну за один обмін.
    $check = $client->domain()->check([$name], fee: ['create' => [1, 2]], currency: 'UAH');

    if ($check->isAvailable($name) !== true) {
        echo $name, ' is not available: ', $check->unavailableReason($name) ?? 'no reason given', "\n";
        $client->logout();
        return;
    }

    $quote = $check->feeFor($name, 'create', 1);
    if ($quote === null) {
        throw new RuntimeException('no create quote — refusing to register at an unknown price');
    }
    if ($check->isPremium($name)) {
        echo "premium name, class {$check->feeClass($name)}\n";
    }
    if (bccomp($available, $quote, 2) < 0) {
        echo "available {$available} is short of {$quote}\n";
        $client->logout();
        return;
    }

    // 3. Реєструйте з межею на тій ціні, яку вам щойно котирували.
    $r = $client->domain()->create($name, [
        'years'      => 1,
        'registrant' => 'C-0001',
        'contacts'   => ['admin' => 'C-0001', 'tech' => 'C-0001'],
        'authInfo'   => 'D0main-Pw',
        'fee'        => $quote,
    ]);

    // 4. Збережіть те, що справді стягнуто, і власні дати та ідентифікатори реєстру.
    echo 'registered ', $r->objectName(), ' until ', $r->expiryDate() ?? '-', "\n";
    echo 'charged    ', $r->feeAmount() ?? '-', ' ', $r->feeCurrency() ?? '', "\n";
    echo 'svTRID     ', $r->svTRID(), "\n";

    if ($r->isPending()) {
        // 1001: у черзі. Домен ще не зареєстровано; вирок надійде як poll-сповіщення.
        $orders->markPending((string) $r->svTRID());
    }

    $client->logout();
} catch (InsufficientFundsException $e) {
    alertBilling($e->getMessage());          // зупиніться; кожна наступна платна команда впаде так само
} catch (CommandException $e) {
    if ($e->eppCode === ResultCode::PARAMETER_VALUE_RANGE_ERROR) {
        echo "the price moved above the cap — nothing was registered or charged\n";
    } else {
        echo 'EPP ', $e->eppCode, ': ', $e->getMessage(), "\n";
    }
} catch (EppException $e) {
    echo 'EPP error: ', $e->getMessage(), "\n";
} finally {
    $client->disconnect();
}
```

Чотири звички з цієї програми, які варто зберегти: котируйте і задавайте межу **тим самим** числом;
зберігайте те, що стягнуто, а не те, що котирувалося; перевіряйте `isPending()`, перш ніж записати
щось як виконане; і сприймайте `2104` як привід зупинитися, а не перейти до наступного імені.

---

## Коди відповіді на цій сторінці

| Код | Значення | Виняток |
|---|---|---|
| `1000` | виконано — числа або котирування в кадрі | — |
| `1001` | команду прийнято, вона завершується офлайн; комісія йде слідом за нею | — |
| `2004` | реальна ціна вища за погоджену вами межу, або період поза діапазоном. **Нічого не стягнуто** | `CommandException` |
| `2005` | сума комісії, яку реєстр не може прочитати як число | `CommandException` |
| `2103` | розширення fee для цієї зони не пропонується | `CommandException` |
| `2104` | недостатньо коштів; нічого не зроблено | `InsufficientFundsException` |
| `2306` | політика реєстру відхиляє запит або погодження | `PolicyException` |

Запит комісій більш ніж на 20 записів і сума в `maxFee()`, яка не є звичайним десятковим числом, —
обидва відхиляються цією бібліотекою з `ValidationException` ще до того, як щось буде надіслано.

---

Див. також: [Домени](domains.md) · [Poll](poll.md) · [Відповіді](responses.md) ·
[Білдери](builders.md) · [Помилки](errors.md)

[← Зміст посібника](README.md)
