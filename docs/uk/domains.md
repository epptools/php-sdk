# Домени

Об'єкти доменів відповідають **RFC 5731**, DNSSEC — **RFC 5910**, відновлення з періоду викупу —
**RFC 3915**, ціни — **RFC 8748**, плюс власне розширення реєстру, якщо воно в нього є. Кожна
доменна команда доступна через `$client->domain()`, і кожна з них повертає
[`Response`](responses.md).

Усе на цій сторінці припускає підключений клієнт із виконаним входом — див. [Сесія](session.md),
щоб його отримати, і [Команди](commands.md), щоб зрозуміти, чим команда й відповідь є загалом.

Дві звички, які варто пронести через усю сторінку:

- **Дати повертаються власним рядком реєстру** (`2027-04-01T09:15:00Z`), ніколи не `DateTime`. Саме
  реєстр вирішує, на який календарний день припадає продовження; переформатування через локальний
  часовий пояс — це те, через що клієнт починає показувати попередній день і від нього ж
  продовжувати.
- **Гроші повертаються точним десятковим рядком**, ніколи не float. Баланс, підсумований у двійковій
  рухомій комі, дрейфує. Використовуйте `bcmath` або цілі числа в найменших одиницях валюти.

## Методи

| Метод | Команда EPP |
|---|---|
| `check(array $names, array $fee = [], ?string $currency = null): Response` | `<check>` + необов'язковий `<fee:check>` |
| `info(string $name, ?string $authInfo = null, string $hosts = 'all'): Response` | `<info>` |
| `create(string $name, array $options = []): Response` | `<create>` |
| `createBuilder(string $name): DomainCreateBuilder` | будує `<create>` |
| `update(string $name, array $options = []): Response` | `<update>` |
| `updateBuilder(string $name): DomainUpdateBuilder` | будує `<update>` |
| `delete(string $name): Response` | `<delete>` |
| `renew(string $name, string $curExpDate, int $years = 1, string\|array\|null $fee = null): Response` | `<renew>` |
| `transfer(string $op, string $name, ?string $authInfo = null, ?int $years = null, string\|array\|null $fee = null): Response` | `<transfer op="…">` |
| `restore(string $name, string\|array\|null $fee = null): Response` | `<update>` + `<rgp:restore op="request"/>` |

`create()` та `update()` приймають масив опцій. **Ключ опції, якого ця бібліотека не розуміє,
відхиляється з `ValidationException` ще до того, як буде побудовано хоч один кадр**, із назвою
найближчого відомого їй ключа. Це важливіше, ніж здається: мовчки проігнорований `'secdns'`
зареєструє домен непідписаним, а реєстр усе одно відповість `1000` — бо, з його погляду, ви цього
й не просили.

---

## check

```php
public function check(array $names, array $fee = [], ?string $currency = null): Response
```

**У каналі передачі:** `<command><check><domain:check><domain:name>…` — RFC 5731 §3.1.1. Кожне ім'я
з `$names` стає одним `<domain:name>`. Коли задано `$fee` або `$currency`, у `<extension>` їде блок
`<fee:check>` (RFC 8748); увесь набір можливостей комісій описано в [Баланс і ціни](balance.md).

Доступність передається в корисному навантаженні, а не в коді відповіді: check, який відповідає
«зайнято», — це **успішна** команда.

```php
$r = $client->domain()->check(['example.com.ua', 'taken.com.ua']);

$r->availability();                        // ['example.com.ua' => true, 'taken.com.ua' => false]
$r->isAvailable('example.com.ua');         // true | false | null
$r->unavailableReason('taken.com.ua');     // 'In use', або null, коли ім'я вільне
```

`isAvailable()` повертає `null`, коли відповідь про це ім'я нічого не сказала. Віддавайте перевагу
йому перед ручним індексуванням `availability()`: там `null` означає і «зайнято», і «ви помилилися
в ключі» — дві відповіді, які не мають права виглядати однаково в рядку, що реєструє ім'я.

```php
foreach ($client->domain()->check($candidates)->availability() as $name => $free) {
    if ($free) {
        $register[] = $name;
    }
}
```

Відповідь про доступність — це знімок, а не бронювання. Між check і create ім'я може зайняти хтось
інший, і ви дізнаєтеся про це з `2302` на create — саме він і є остаточною відповіддю.

**Коди відповіді:** `1000` на будь-який коректно сформований check. `2005` називає синтаксично
недійсне доменне ім'я, `2307` — зону, яку цей реєстр не обслуговує, `2306` — доданий блок комісії,
який відхиляє політика реєстру. Запит комісій більш ніж на 20 записів ця бібліотека відхиляє з
`ValidationException` ще до надсилання.

---

## info

```php
public function info(string $name, ?string $authInfo = null, string $hosts = 'all'): Response
```

**У каналі передачі:** `<command><info><domain:info><domain:name hosts="all">` — RFC 5731 §3.1.2.
Передайте `$authInfo` — і він піде як `<domain:authInfo><domain:pw>`; саме так реєстратор, який
**не** є власником домену, читає повний запис.

`$hosts` обирає, які хости перелічить відповідь, і є атрибутом `hosts` із RFC 5731:

| значення | що перелічує відповідь |
|---|---|
| `all` (типово) | делеговані сервери імен і підпорядковані хости |
| `del` | лише делеговані сервери імен |
| `sub` | лише підпорядковані хости |
| `none` | нічого з цього |

```php
$info = $client->domain()->info('example.com.ua');

$info->objectName();          // 'example.com.ua'
$info->roid();                // власний ідентифікатор об'єкта в реєстрі
$info->statuses();            // ['ok'] або ['clientHold', 'clientTransferProhibited', …]
$info->expiryDate();          // '2027-04-01T09:15:00Z' — власний рядок реєстру
$info->createdDate();         // crDate            $info->createdBy();   // crID
$info->updatedDate();         // upDate, або null  $info->updatedBy();   // upID, або null
$info->sponsor();             // clID — обліковий запис, якому домен належить зараз
$info->registrarOfRecord();   // ідентифікатор, який публікують WHOIS/RDAP реєстру, коли він інший
$info->transferDate();        // коли домен востаннє змінив власника, або null

$info->registrant();          // ідентифікатор контакту реєстранта
$info->contacts();            // ['admin' => ['acme-01'], 'tech' => ['acme-01', 'acme-02']]
$info->adminContacts();       // лише ця роль — також techContacts() / billingContacts()
$info->contactsFor('tech');   // будь-яка роль, без урахування регістру; [] коли її ніхто не тримає
$info->allContacts();         // усі ідентифікатори, включно з реєстрантом, без дублікатів

$info->nameservers();         // імена, незалежно від того, чи відповів реєстр hostObj, чи hostAttr
$info->nameserverAddresses(); // вбудовані glue-адреси за іменем сервера, коли реєстр їх надсилає
$info->subordinateHosts();    // хости, що живуть ПІД цим доменом

$info->authInfo();            // код трансферу — див. попередження нижче
$info->license();             // номер торгової марки або ліцензії, або null
$info->rgpStatus();           // ['redemptionPeriod'] тощо, або []
$info->isSigned();            // чи несе домен якісь дані DNSSEC
$info->dsRecords();           // [['keyTag'=>…, 'alg'=>…, 'digestType'=>…, 'digest'=>…], …]
$info->keyRecords();          // [['flags'=>…, 'protocol'=>…, 'alg'=>…, 'pubKey'=>…], …]
$info->prices();              // ['renewal' => ['value'=>'180.00', 'currency'=>'UAH'], …]
$info->priceChannel();        // з якого рядка каталогу взято ці ціни, або null
```

`authInfo()` — це секрет, який дозволяє **будь-якому** реєстратору забрати у вас домен. Він
повертається лише реєстратору-власнику. Ніколи не пишіть його в журнал, ніколи не вставляйте у
звернення до підтримки і змінюйте його, щойно він побував у клієнта — див.
[Відкликання витеклого коду трансферу](#відкликання-витеклого-коду-трансферу).

Два аксесори варто читати разом. `nameservers()` дає імена за будь-якої з двох моделей EPP, тож
беріть його для списку; `nameserverAddresses()` заповнюється лише там, де реєстр відповідає
вбудованими glue-адресами, тож порожній результат **не** означає, що домен без делегування. Там, де
реєстр відповідає посиланнями на об'єкти хостів, адреси ви отримуєте одним
[`host()->info()`](hosts.md#info) на кожне ім'я.

`subordinateHosts()` — це те, що варто перевірити перед видаленням: реєстр відмовляється видаляти
домен, поки під ним живуть хости.

**Коди відповіді:** `1000`; `2202` (неправильний `authInfo` як у не-власника); `2303` (такого
домену немає).

---

## create

```php
public function create(string $name, array $options = []): Response
```

**У каналі передачі:** `<command><create><domain:create>` — RFC 5731 §3.2.1, плюс `<secDNS:create>`
(RFC 5910), `<registry:create><registry:license>` та `<fee:create>` (RFC 8748) у `<extension>`, коли ви їх
просите. **Комісія за create стягується в разі успіху.**

### Кожна опція

| ключ | значення | канал передачі |
|---|---|---|
| `years` | `int` | `<domain:period unit="y">` — пропустіть, щоб узяти типовий термін реєстру |
| `registrant` | ідентифікатор | `<domain:registrant>` |
| `contacts` | `role => handle` або `role => [handle, …]` | один `<domain:contact type="…">` на кожен ідентифікатор |
| `nameservers` (пишеться також `nameServers`) | `string[]` або список `['name' => …, 'addresses' => [...]]` | `<domain:ns>`, що містить `<domain:hostObj>` або `<domain:hostAttr>` |
| `authInfo` | рядок | `<domain:authInfo><domain:pw>` |
| `license` | рядок | `<registry:license>` усередині `<registry:create>` |
| `secDNS` | `['dsData' => [...], 'keyData' => [...], 'maxSigLife' => int]` | `<secDNS:create>` |
| `fee` | `'100.00'` або `['amount' => '100.00', 'currency' => 'UAH']` | `<fee:create>` — межа, на яку ви погоджуєтеся |

### Перша реєстрація

```php
use EppTools\Client;
use EppTools\Config;
use EppTools\Exception\EppException;

$client = new Client(Config::fromArray([
    'host'     => 'epp.registry.example',
    'clid'     => 'EXAMPLE',
    'password' => 'your-secret',
    'caFile'   => '/path/to/registry-ca.pem',
]));

try {
    $client->connect();
    $client->login();

    $r = $client->domain()->create('example.com.ua', [
        'years'       => 1,
        'registrant'  => 'acme-01',
        'contacts'    => ['admin' => 'acme-01', 'tech' => ['acme-01', 'acme-02']],
        'nameservers' => ['ns1.acme.example', 'ns2.acme.example'],
        'authInfo'    => 'D0main-Pw',
    ]);

    // Прочитайте відповідь. Create відповідає іменем і датами, які призначив реєстр.
    echo $r->objectName(), ' created ', $r->createdDate(), "\n";
    echo 'expires: ', $r->expiryDate() ?? '-', "\n";
    echo 'charged: ', $r->feeAmount() ?? '-', ' ', $r->feeCurrency() ?? '', "\n";

    if ($r->isPending()) {
        // 1001: реєстр поставив реєстрацію в чергу. Домен ЩЕ НЕ зареєстровано, а результат
        // надійде пізніше як poll-сповіщення — див. poll.md.
        echo "queued for offline processing (svTRID {$r->svTRID()})\n";
    }

    $client->logout();
} catch (EppException $e) {
    echo 'EPP error: ', $e->getMessage(), "\n";
} finally {
    $client->disconnect();
}
```

### Контакти: один ідентифікатор на роль або кілька

Роль приймає скаляр або список, і кожен ідентифікатор стає власним `<domain:contact type="…">` —
саме це дозволяє RFC 5731. Скільки їх може тримати одна роль — це політика реєстру.

```php
'contacts' => ['admin' => 'acme-01', 'tech' => ['acme-01', 'acme-02'], 'billing' => 'acme-03'],
```

Реєстрант до них **не** належить — це окремий елемент із власним значенням, і задається він ключем
`registrant`.

### Сервери імен: дві моделі
Сервер імен — це або **ім'я**, тобто посилання на [об'єкт хоста](hosts.md), який уже існує в
реєстрі, або ім'я **з вбудованими glue-адресами**. Запитайте свій реєстр, яку модель він приймає.

```php
// Посилання на об'єкти хостів (<domain:hostObj>): спершу створіть хости.
'nameservers' => ['ns1.acme.example', 'ns2.acme.example'],

// Вбудовані glue-адреси (<domain:hostAttr>): адреси подорожують разом з іменем.
'nameservers' => [
    ['name' => 'ns1.example.com.ua', 'addresses' => ['203.0.113.1', '2001:db8::1']],
    ['name' => 'ns2.example.com.ua', 'addresses' => ['203.0.113.2']],
],
```

Версія IP визначається із самого літерала, тож `v4` і `v6` отримують правильні позначки без того,
щоб ви вказували, де яка.

RFC 5731 робить `<domain:ns>` *вибором* між двома моделями, тож одна команда використовує або одну,
або другу. Суміш тут дає `ValidationException`, а не голий `2001` від реєстру, який не називає
жодного поля:

```php
'nameservers' => ['ns1.acme.example', ['name' => 'ns2.acme.example', 'addresses' => ['203.0.113.2']]],
// ValidationException: nameservers must be all names or all name-with-glue, not a mixture
```

Реєстрація взагалі без `nameservers` цілком законна: домен лишається без делегування, і реєстр
повідомляє про нього `inactive` — а це стан, а не помилка.

### authInfo

`<domain:authInfo>` у create обов'язковий, тож елемент виходить завжди. Задайте опцію `authInfo` —
і в ньому поїде ваше значення; пропустіть її — і замість нього піде порожній `<domain:pw/>`, що
передає вибір власній політиці реєстру для цієї зони: багато зон тоді самі генерують код для вас, і
ви зчитуєте його через `info()`. Наданий вами код має задовольняти політику надійності зони, інакше
create відхиляється з `2306`.

### secDNS під час create (RFC 5910)

```php
$client->domain()->create('example.com.ua', [
    'years'      => 1,
    'registrant' => 'acme-01',
    'secDNS'     => [
        'maxSigLife' => 1209600,
        'dsData'     => [[
            'keyTag'     => 12345,
            'alg'        => 13,
            'digestType' => 2,
            'digest'     => '49FD46E6C4B45C55D4AC69E1F3B2A0D7C8E5904B1A2C3D4E5F60718293A4B5C6',
            // Необов'язково: DNSKEY, з якого обчислено дайджест. Реєстр, який його приймає,
            // може перевірити дайджест за вас; той, що не приймає, відповідає 2306, а не ігнорує.
            // 'keyData' => ['flags' => 257, 'protocol' => 3, 'alg' => 13, 'pubKey' => 'AwEAA…'],
        ]],
        // Або голі відкриті ключі замість DS-записів, там, де реєстр приймає такі:
        // 'keyData' => [['flags' => 257, 'protocol' => 3, 'alg' => 13, 'pubKey' => 'AwEAA…']],
    ],
]);
```

Масив `secDNS` приймає `dsData`, `keyData` і `maxSigLife` — і більше нічого. Масив `secDNS`, у якому
немає ні `dsData`, ні `keyData`, не надсилає блоку DNSSEC узагалі, бо `<secDNS:create/>` без
нащадків не проходить перевірку схеми в реєстрі.

### ліцензія (там, де реєстр її вимагає)

Деякі реєстри не реєструють окремі імена без номера торговельної марки або ліцензії — найчастіше це
короткі й дорогі імена безпосередньо під доменом верхнього рівня. Там, де це так, передавайте його
в `license`:

```php
$client->domain()->create('example.com.ua', [
    'years'      => 1,
    'registrant' => 'acme-01',
    'license'    => 'TM-2026-000123',  // виходить як <registry:license> усередині <registry:create>
]);
```

Він їде у **власному** розширенні реєстру, і його простір імен клієнт читає з `<greeting>` — див.
[Команди](commands.md#власні-розширення-вашого-реєстру). Реєстру, який такого розширення не оголошує,
замість кадру, який той би проігнорував, буде кинуто `ConfigException`.

Яким саме іменам вона потрібна — це політика реєстру, а не протоколу, тож питайте у свого. Про те,
що ви не вгадали, скажуть дві відмови: ім'я, якому ліцензія потрібна, але її не передали, зазвичай
відхиляється з `2003` (бракує обов'язкового параметра), а ліцензія, надіслана туди, де на неї не
чекають, — з `2306` (неприпустиме за політикою значення параметра).

### fee: обмеження суми, на яку ви погоджуєтеся

```php
'fee' => '100.00',                                    // «погоджуюся заплатити до 100.00»
'fee' => ['amount' => '100.00', 'currency' => 'UAH'], // …у цій валюті
```

Це **межа, а не ціна, яку встановлюєте ви**. Якщо реальна ціна вища — змінився тариф, ім'я виявилося
преміальним, у вас застарів кеш — реєстр відхиляє команду з `2004` і не стягує нічого, замість того
щоб мовчки списати більше. Без цього ключа команда виконується, і стягується власна ціна реєстру.
Повністю тему розкрито в [Баланс і ціни](balance.md).

**Коди відповіді:** `1000`; `1001`, коли реєстр ставить реєстрацію в чергу; `2003` / `2004` / `2005`
/ `2306` (валідація та політика, включно з межею `fee`, нижчою за реальну ціну); `2104` (недостатньо
коштів — [зупиніть пакет](errors.md)); `2302` (уже зареєстровано); `2103` (DNSSEC у цій зоні не
пропонується); `2307` (зона не обслуговується).

### Побудова create крок за кроком

```php
public function createBuilder(string $name): DomainCreateBuilder
```

Та сама команда, той самий кадр, той самий результат — білдер викликає `create()`. Змінюється те, що
описка стає неіснуючим методом, про який вам скаже редактор. Кожен крок описано в
[Білдери](builders.md).

---

## update

```php
public function update(string $name, array $options = []): Response
```

**У каналі передачі:** `<command><update><domain:update>` — RFC 5731 §3.2.5, із `<secDNS:update>`,
`<rgp:update>`, `<registry:update>` та `<fee:update>` у `<extension>` за потреби.

**Оновлення в EPP — це дельта, а не заміна.** Те, чого ви не згадали, залишається точно таким, як
було. Блок, у який потрапляє зміна, *і є* семантикою команди:

| блок | значення |
|---|---|
| `add` | лишити те, що є, і додати оце |
| `rem` | забрати оце, решту лишити |
| `chg` | замінити це однозначне поле |

| ключ | значення |
|---|---|
| `add` / `rem` | `['ns' => [...], 'contacts' => ['role' => handle\|[handles]], 'statuses' => [...]]` |
| `chg` | `['registrant' => handle, 'authInfo' => string, 'clearAuthInfo' => true]` |
| `secDNS` | `['add' => [...], 'rem' => [...], 'remAll' => true, 'maxSigLife' => int]` |
| `restore` | `true` — див. [restore](#restore) |
| `license` | рядок — замінює номер торговельної марки або ліцензії |
| `fee` | межа, на яку ви погоджуєтеся, коли зміна платна |

```php
$r = $client->domain()->update('example.com.ua', [
    'add' => [
        'ns'       => ['ns3.acme.example'],
        'contacts' => ['tech' => 'acme-02'],
        'statuses' => ['clientTransferProhibited'],
    ],
    'rem' => [
        'ns'       => ['ns2.acme.example'],
        'statuses' => ['clientHold'],
    ],
    'chg' => [
        'registrant' => 'acme-09',
        'authInfo'   => 'New-D0main-Pw',
    ],
]);

echo $r->code(), ' ', $r->message(), "\n";   // 1000, або 1001, якщо реєстр поставив у чергу

// Update відповідає результатом, а не об'єктом. Перечитайте новий стан тоді, коли вам
// треба його зберегти:
$after = $client->domain()->info('example.com.ua');
echo implode(', ', $after->nameservers()), "\n";
echo implode(', ', $after->statuses()), "\n";
```

Статуси, які ви можете встановлювати, — це родина `client*`: `clientHold`,
`clientUpdateProhibited`, `clientTransferProhibited`, `clientDeleteProhibited`,
`clientRenewProhibited`. Статуси `server*` належать реєстру, і спроба їх зачепити повертається з
`2304`. `ok` та `inactive` обчислюються і не належать нікому.

Порожній блок `add` чи `rem` не надсилається взагалі. Оновлення, яке не несе ні `add`, ні `rem`, ні
`chg` — і жодної зміни в розширенні, — це порожня команда, і реєстр відхиляє її з `2003`.

### secDNS під час update (RFC 5910)

Оновлення й тут є дельтою, і форма в нього інша, ніж у блоці create:

```php
// Ротація ключа: видаліть старий DS і додайте новий у тій самій команді, щоб не було
// проміжку, коли домен лишається непідписаним.
$client->domain()->update('example.com.ua', [
    'secDNS' => [
        'rem' => ['dsData' => [[
            'keyTag' => 12345, 'alg' => 13, 'digestType' => 2,
            'digest' => '49FD46E6C4B45C55D4AC69E1F3B2A0D7C8E5904B1A2C3D4E5F60718293A4B5C6',
        ]]],
        'add' => ['dsData' => [[
            'keyTag' => 54321, 'alg' => 13, 'digestType' => 2,
            'digest' => 'A1B2C3D4E5F60718293A4B5C6D7E8F90112233445566778899AABBCCDDEEFF00',
        ]]],
    ],
]);

// Повністю зняти підпис із домену:
$client->domain()->update('example.com.ua', ['secDNS' => ['remAll' => true]]);

// Замінити весь набір ключів за одну операцію:
$client->domain()->update('example.com.ua', [
    'secDNS' => ['remAll' => true, 'add' => ['dsData' => [[/* … */]]]],
]);

// Змінити лише час життя підпису:
$client->domain()->update('example.com.ua', ['secDNS' => ['maxSigLife' => 1209600]]);
```

Запис, названий у `rem`, має збігатися з тим, що тримає реєстр, у кожному полі, а не лише за
keyTag. `remAll` і `rem` — альтернативи: коли є обидва, виходить `remAll`;
[білдер оновлення](builders.md) відмовляє в такому поєднанні прямо, замість того щоб вибирати за
вас.

Масив `secDNS`, у якому немає ні `add`, ні `rem`, ні `remAll`, ні `maxSigLife`, не надсилає блоку
DNSSEC узагалі: `<secDNS:update/>` без нащадків — це `2003` у реєстрі за те, що читається як команда
без дії, і задумана вами зміна DNSSEC загубилася б, а команда все одно повідомила б про невдачу.

### Відкликання витеклого коду трансферу
```php
// Код потрапив туди, куди не мав. Приберіть його зовсім:
$client->domain()->update('example.com.ua', ['chg' => ['clearAuthInfo' => true]]);

// Пізніше, коли клієнту знову знадобиться код:
$client->domain()->update('example.com.ua', ['chg' => ['authInfo' => 'Fresh-D0main-Pw']]);
```

`clearAuthInfo` надсилає `<domain:authInfo><domain:null/></domain:authInfo>`, що **видаляє** секрет.
Встановити `authInfo` в `''` — це не те саме і не розв'язання: порожній пароль лишається значенням,
яке той, хто його має, може пред'явити, тож домен лишається рівно таким самим рухомим, як був.

Ці два ключі взаємовиключні — схема не має способу виразити обидва, — тож прохання про обидва в
одному `chg` викликає `ValidationException` ще до того, як щось буде надіслано.

**Коди відповіді:** `1000`; `1001`, коли поставлено в чергу; `2003` / `2004` / `2005` / `2306`;
`2303` (такого домену немає); `2304` (статус забороняє); `2305` (зв'язок забороняє); `2103` (DNSSEC
тут не пропонується).

### Побудова update крок за кроком

```php
public function updateBuilder(string $name): DomainUpdateBuilder
```

Білдер оновлення називає блок, у який потрапляє кожна зміна, — `addNameserver`, `remStatus`,
`changeRegistrant`, `clearAuthInfo` — з тієї самої причини, що й масив. Див.
[Білдери](builders.md).

---

## delete

```php
public function delete(string $name): Response
```

**У каналі передачі:** `<command><delete><domain:delete>` — RFC 5731 §3.2.2.

```php
$before = $client->domain()->info('example.com.ua');
if ($before->subordinateHosts() !== []) {
    // Реєстр відмовляє у видаленні, поки під цим доменом живуть хости (2305).
    throw new RuntimeException('remove ' . implode(', ', $before->subordinateHosts()) . ' first');
}

$r = $client->domain()->delete('example.com.ua');
echo $r->code(), ' ', $r->message(), "\n";
```

Що саме зробить delete, залежить від того, де домен перебуває у своєму життєвому циклі: у межах
вікна add-grace він видаляється негайно, інакше — переходить у `redemptionPeriod`, звідки його можна
[відновити](#restore), доки вікно не закриється і ім'я не буде очищено. Прочитайте `rgpStatus()` у
наступному `info()`, щоб побачити, що саме сталося.

**Коди відповіді:** `1000`; `1001`, коли поставлено в чергу; `2303`; `2304` (напр.
`clientDeleteProhibited`); `2305` (під доменом усе ще існують хости).

---

## renew

```php
public function renew(string $name, string $curExpDate, int $years = 1, string|array|null $fee = null): Response
```

**У каналі передачі:** `<command><renew><domain:renew>` з `<domain:name>`, `<domain:curExpDate>` та
`<domain:period unit="y">` — RFC 5731 §3.2.3. **Комісія за renew стягується в разі успіху.**

`$curExpDate` має дорівнювати **поточній** даті завершення терміну реєстрації домену. Це не
формальність: саме вона не дає дубльованому чи повтореному renew додати ще один рік. Читайте її з
реєстру, а не зі свого кешу.

**Передавайте `expiryDate()` як є.** Це два різні XML-типи — `<domain:exDate>` є відміткою часу, а
`<domain:curExpDate>` — датою, — і денну частину бібліотека бере сама:

```php
$info = $client->domain()->info('example.com.ua');
// $info->expiryDate() — це '2027-04-01T09:15:00.0Z'; у канал передачі піде '2027-04-01'.

$r = $client->domain()->renew('example.com.ua', $info->expiryDate(), 1, ['amount' => '90.00', 'currency' => 'UAH']);

echo 'new expiry: ', $r->expiryDate(), "\n";   // власний рядок реєстру — зберігайте як є
echo 'charged:    ', $r->feeAmount() ?? '-', ' ', $r->feeCurrency() ?? '', "\n";
```

Дата береться **такою, якою її написав сервер**, без розбору і без переведення часових поясів. Це
зроблено навмисно: відмітки часу в EPP — в UTC, і дата завершення в реєстру теж у UTC, тож клієнт,
який переформатовує її через місцевий пояс, для кожного домену, що завершується близько опівночі,
влучає на добу в той чи інший бік — і далі продовжує за датою, якої в реєстру немає. Якщо потрібен
місцевий час, переводьте його там, де показуєте, а не перед надсиланням назад.

Розбіжність у `curExpDate` повертається як `2105`, і саме цій відповіді треба вірити: вона означає,
що термін реєстрації домену не такий, як ви думали, тож перечитайте його, перш ніж робити будь-що
інше. `2105` ніколи не є підставою повторити той самий кадр.

**Коди відповіді:** `1000`; `2004` (період поза діапазоном); `2105` (розбіжність `curExpDate` або
домен не підлягає продовженню); `2104` (недостатньо коштів); `2303`; `2304`; `2306`.

---

## transfer

```php
public function transfer(string $op, string $name, ?string $authInfo = null, ?int $years = null, string|array|null $fee = null): Response
```

**У каналі передачі:** `<command><transfer op="…"><domain:transfer>` — RFC 5731 §3.2.4 (і §3.1.3 для
`query`). `$op` — це одне з `request`, `query`, `approve`, `reject`, `cancel`.

| `$op` | хто надсилає | що робить |
|---|---|---|
| `request` | реєстратор, що приймає | запитує домен, із поточним `authInfo` |
| `query` | будь-яка зі сторін | повідомляє, на якій стадії запит, нічого не змінюючи |
| `approve` | поточний реєстратор-власник | приймає запит, що очікує |
| `reject` | поточний реєстратор-власник | відмовляє в запиті, що очікує |
| `cancel` | реєстратор, що запитує | відкликає власний запит |

`$years` виходить як `<domain:period unit="y">` **лише тоді, коли ви передаєте число**, тож `null`
пропускає елемент цілком. Що з двох потрібне зоні — це політика реєстру: зони, які вкладають у
трансфер обов'язкове продовження на один рік, беруть `1` (або пропущене типове значення), а зони, де
трансфер безплатний і нічого не змінює, хочуть, щоб елемента не було взагалі. Запитайте свій реєстр,
що діє в зоні, з якої ви переносите домен.

### Запит трансферу до себе

```php
$r = $client->domain()->transfer('request', 'example.com.ua', 'the-code-from-the-losing-registrar', 1);

$r->code();               // 1001 — прийнято й очікує, а не виконано
$r->transferStatus();     // 'pending'

$t = $r->transfer();      // увесь блок trnData
// [
//   'status'       => 'pending',
//   'requestedBy'  => 'EXAMPLE',                 // reID
//   'requestedAt'  => '2026-04-01T09:15:00Z',     // reDate
//   'actingClient' => 'DELTA',                 // acID — хто має відповісти
//   'actBy'        => '2026-04-06T09:15:00Z',     // acDate — строк
//   'expiryDate'   => '2028-04-01T09:15:00Z',     // дата завершення, яка діятиме
// ]
```

Сам по собі `transferStatus()` каже, що трансфер очікує, але не каже, чий він і скільки часу
лишилося на дію. `transfer()` дає `actBy`, і саме ця дата має значення: **мовчання завершує
трансфер.** Після строку рішення ухвалює реєстр, а в цих зонах він трансфер підтверджує.
Реєстратор-власник, який підшиває poll-сповіщення замість того, щоб на нього відповісти, втрачає
домен.

### Відповідь на трансфер як реєстратор, що втрачає домен

Запит надходить до вас як [poll-сповіщення](poll.md) з `trnData`. Відповідайте на нього:

```php
$client->poll()->drain(function (EppTools\Response $notice) use ($client): void {
    $t = $notice->transfer();
    if ($t === null || $t['status'] !== 'pending') {
        return;
    }
    $name = $notice->objectName();

    if (customerAuthorisedTheMove($name)) {
        $client->domain()->transfer('approve', $name);
    } else {
        $client->domain()->transfer('reject', $name);
    }
});
```

### Перевірка й відкликання

```php
$client->domain()->transfer('query', 'example.com.ua');    // 2300 очікує, 2301 нічого не очікує
$client->domain()->transfer('cancel', 'example.com.ua');   // відкликати власний запит
```

Поки домен у `pendingTransfer`, жодна інша операція з ним не приймається, включно з автоматичними.

**Коди відповіді:** `1000` / `1001`; `2201` (не ваш об'єкт, щоб діяти з ним); `2202` (неправильний
`authInfo`); `2300` (уже очікує); `2301` (немає нічого, що очікує, щоб схвалити, відхилити,
скасувати чи запитати); `2304`; `2306`; `2106` (не підлягає трансферу).

---

## restore

```php
public function restore(string $name, string|array|null $fee = null): Response
```

**У каналі передачі:** `<update>`, єдиним вмістом якого є `<rgp:update><rgp:restore op="request"/>` —
RFC 3915. Це рівно те саме, що `update($name, ['restore' => true])`, і вони взаємозамінні.
**Комісія за відновлення стягується в разі успіху**, і це зазвичай найдорожча операція в каталозі.

Жодні `add`, `rem` чи `chg` не можуть їхати разом із відновленням. Змінюйте домен після нього,
другою командою.

```php
$info = $client->domain()->info('example.com.ua');

if (in_array('redemptionPeriod', $info->rgpStatus(), true)) {
    $r = $client->domain()->restore('example.com.ua', '1000.00');   // ваша межа, а не оприлюднена ціна

    echo $r->code(), "\n";                        // 1000 відновлено, або 1001 у черзі
    echo 'charged: ', $r->feeAmount() ?? '-', "\n";

    $after = $client->domain()->info('example.com.ua');
    echo 'rgp:     ', implode(', ', $after->rgpStatus() ?: ['-']), "\n";
    echo 'expires: ', $after->expiryDate(), "\n";
}
```

Читайте `rgpStatus()`, а не `statuses()`: стани викупу надходять у `<extension>` як
`<rgp:infData>`, тож клієнт, який читає лише `<domain:status>`, побачить домен за кілька днів до
видалення зі звичайним `ok`.

Відновлення можливе лише в межах вікна викупу. Після нього ім'я вивільняється, і відновлювати вже
нічого.

**Коди відповіді:** `1000`; `1001`, коли відновлення завершується асинхронно; `2104` (недостатньо
коштів); `2303`; `2304` (домен не в тому стані, з якого його можна відновити); `2306`.

---

## Коли команда, що змінює дані, зазнала невдачі, а ви не знаєте, чи вона відбулася

Тайм-аут читання або розірване з'єднання посеред `create`, `renew` чи `transfer` лишає справді
невідомий результат: реєстр міг виконати команду і списати з вас гроші ще до того, як відповідь
загубилася. Ні ця бібліотека, ні виняток різниці не бачать.

**Не повторюйте просто так.** Сліпий повтор — це те, як домен реєструють — і оплачують — двічі.
Замість цього запитайте в реєстру, як є насправді: `info()` для create, а для renew — `expiryDate()`,
звірений із тим, чого ви очікували. Повторюйте, лише якщо об'єкт справді в тому стані, з якого ви
починали. Повне правило разом із таксономією винятків — у [Помилки](errors.md).

---

## Коди відповіді на цій сторінці

| Код | Значення | Виняток |
|---|---|---|
| `1000` | виконано | — |
| `1001` | прийнято, завершується офлайн; результат надходить через [poll](poll.md) | — |
| `2003` | бракує обов'язкового параметра | `CommandException` |
| `2004` | значення поза діапазоном — включно з межею `fee`, нижчою за реальну ціну | `CommandException` |
| `2005` | значення синтаксично недійсне | `CommandException` |
| `2103` | розширення не підтримується для цієї зони | `CommandException` |
| `2104` | недостатньо коштів; нічого не зареєстровано і нічого не стягнуто | `InsufficientFundsException` |
| `2105` | розбіжність `curExpDate` або домен не підлягає продовженню | `CommandException` |
| `2106` | не підлягає трансферу | `CommandException` |
| `2201` | не ваш об'єкт, щоб діяти з ним | `AuthorizationException` |
| `2202` | неправильний `authInfo` | `AuthorizationException` |
| `2300` / `2301` | уже очікує трансферу / не очікує трансферу | `CommandException` |
| `2302` | уже зареєстровано | `ObjectExistsException` |
| `2303` | такого домену немає | `ObjectDoesNotExistException` |
| `2304` / `2305` | статус або зв'язок забороняє це | `ObjectStatusException` |
| `2306` / `2308` | політика реєстру відхиляє це значення | `PolicyException` |
| `2307` | зона не обслуговується | `CommandException` |

`ResultCode` має іменовану константу для кожного з них. Повна таксономія, правила повторів та
альтернатива `throwOnFailure(false)` — у [Помилки](errors.md).

---

Див. також: [Контакти](contacts.md) · [Хости](hosts.md) · [Poll](poll.md) ·
[Баланс і ціни](balance.md) · [Відповіді](responses.md) · [Білдери](builders.md)

[← Зміст посібника](README.md)
