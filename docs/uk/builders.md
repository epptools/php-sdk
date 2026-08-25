# Білдери

Команди, що приймають масив опцій, можна також зібрати по одному іменованому кроку за раз.

```php
$response = $client->domain()->createBuilder('example.com.ua')
    ->years(1)
    ->registrant('C-0001')
    ->adminContact('C-0001')
    ->techContact('C-0002')
    ->nameservers('ns1.acme.example', 'ns2.acme.example')
    ->authInfo('D0main-Pw')
    ->maxFee('100.00', 'UAH')
    ->send();
```

**Та сама команда, той самий кадр, той самий результат.** Білдер не будує власного XML: `send()`
передає свої опції звичайному методу, тож білдер і рівнозначний масив дають ідентичний кадр, і кожна
перевірка, що діє для одного, діє й для другого.

Змінюється те, де спливає помилка. Масив опцій приймає будь-який ключ, тож `'yeras' => 1` ловиться
лише тому, що бібліотеку навчили всьому переліку ключів і вона відмовляє в тому, чого в переліку
немає. У білдера немає ключа, в якому можна помилитися: `->yeras(1)` — це метод, якого не існує, і
редактор скаже вам про це просто під час набору.

Усе тут припускає під'єднаного клієнта, який увійшов у сесію — див. [Сесія](session.md).

## П'ять білдерів

| Клас | Звідки береться | Надсилає |
|---|---|---|
| `DomainCreateBuilder` | `$client->domain()->createBuilder(string $name)` | `domain:create` |
| `DomainUpdateBuilder` | `$client->domain()->updateBuilder(string $name)` | `domain:update` |
| `ContactCreateBuilder` | `$client->contact()->createBuilder(string $id, string $email)` | `contact:create` |
| `ContactUpdateBuilder` | `$client->contact()->updateBuilder(string $id)` | `contact:update` |
| `HostUpdateBuilder` | `$client->host()->updateBuilder(string $name)` | `host:update` |

Вони живуть у `EppTools\Builder\`. Ви ніколи не створюєте їх напряму — це робить обробник, тож білдер
уже знає, через якого клієнта надсилати.

Білдера для `check`, `info`, `renew`, `transfer`, `delete` чи `restore` навмисно немає. Ці команди
приймають позиційні аргументи, які мова перевіряє й без того; білдер додав би церемонію, не прибравши
жодного класу помилок.

---

## Чотири правила, що діють для кожного білдера

### 1. Кожен списковий крок накопичує

Передати кілька одразу, викликати крок ще раз або і те, і те — це одне й те саме:

```php
->techContact('C-0002', 'C-0003')
->techContact('C-0002')->techContact('C-0003')   // ідентично
```

Саме це робить білдер таким, що читається так, як поводиться — усередині циклу чи за умовою:

```php
$builder = $client->domain()->createBuilder('example.com.ua')->years(1)->registrant('C-0001');

foreach ($nameservers as $host) {
    $builder->nameserver($host);        // кожен виклик додає один
}
if ($needsDnssec) {
    $builder->dsRecord(12345, 13, 2, $digest);
}

$builder->send();
```

Одиничні кроки натомість **замінюють**: `->years(1)->years(2)` лишає `2`, так само як подвійне
присвоєння змінній. Таблиці нижче кажуть, що з них що.

Кроки, які приймають імена, ідентифікатори та адреси — сервери імен, контакти, статуси доменів і
хостів, glue-адреси — відкидають порожнє чи складене з пробілів значення, а не надсилають його як
порожній елемент, тож цикл по списку з порожнім рядком усередині не породжує `<domain:hostObj/>`. Два
кроки статусів контакту, `ContactUpdateBuilder::addStatus()` і `remStatus()`, передають те, що ви їм
дали, як є, — тож фільтруйте такий список самі, якщо в ньому може бути порожнє значення.

### 2. Нічого не надсилається до `send()`

До того білдер — звичайне значення. Тримайте його, передавайте в іншу функцію, збирайте в одному
місці, а відправляйте в іншому.

```php
$builder = $client->domain()->createBuilder('example.com.ua')->years(1)->registrant('C-0001');
// …до реєстру ще нічого не дійшло…
$response = $builder->send();     // а тепер дійшло
```

`send()` повертає [`Response`](responses.md), рівно так само, як і прямий виклик.

### 3. `toOptions()` віддає точно те, що приймає прямий виклик, — копією

```php
public function toOptions(): array
```

Доступний у кожному білдері.

```php
$builder = $client->domain()->createBuilder('example.com.ua')
    ->years(1)
    ->registrant('C-0001')
    ->techContact('C-0002');

$builder->toOptions();
// ['years' => 1, 'registrant' => 'C-0001', 'contacts' => ['tech' => ['C-0002']]]

// Тож ось та сама команда, іншою дорогою:
$client->domain()->create('example.com.ua', $builder->toOptions());
```

Важать дві властивості:

- **Це рівно той масив, який приймає прямий метод.** Саме це робить білдер придатним для черги:
  серіалізуйте `toOptions()`, покладіть у чергу, а воркер нехай викличе з ним `create()`.
- **Це глибока копія.** Віддача живого масиву дозволила б йому змінюватися під викликачем щоразу, коли
  додається ще один крок, — і те, що ви записали в журнал, могло б не збігтися з тим, що надіслали.
  Ви отримуєте значення, яке вже завершене.

Виклик нічого не надсилає й не витрачає білдер.

### 4. Білдер надсилає один раз

```php
$builder = $client->domain()->createBuilder('example.com.ua')->years(1)->registrant('C-0001');

$builder->send();
$builder->send();
// ValidationException: EppTools\Builder\DomainCreateBuilder has already been sent.
//                      A builder carries one command; build another rather than re-sending this one.
```

Другий `send()` на створенні — це друга реєстрація і друге списання, і це ніколи не те, що мав на
увазі викликач: повтор після збою — не те саме, що перепрогравання об'єкта, який уже пішов. Зберіть
новий білдер, вони нічого не коштують. Якщо перший `send()` завалився так, що підсумок лишився
невідомим, прочитайте
[Помилки](errors.md#коли-операція-завалилася-а-ви-не-знаєте-чи-вона-сталася), перш ніж робити
взагалі щось.

---

## DomainCreateBuilder

```php
$client->domain()->createBuilder(string $name): DomainCreateBuilder
```

Надсилає `domain:create` (RFC 5731 §3.2.1). Кожна опція [`domain()->create()`](domains.md#create) має
тут свій крок.

| Крок | Встановлює | Накопичує? |
|---|---|---|
| `years(int $years): self` | `years` — `<domain:period unit="y">`. Пропустіть його, і реєстр застосує власний типовий строк | замінює |
| `registrant(string $handle): self` | `registrant` — власник домену | замінює |
| `contact(string $role, string ...$handles): self` | `contacts[$role][]` — один `<domain:contact type="…">` на ідентифікатор | накопичує |
| `adminContact(string ...$handles): self` | `contacts['admin'][]` | накопичує |
| `techContact(string ...$handles): self` | `contacts['tech'][]` | накопичує |
| `billingContact(string ...$handles): self` | `contacts['billing'][]` | накопичує |
| `nameserver(string $host): self` | `nameservers[]` — одне посилання на об'єкт хоста (`<domain:hostObj>`) | накопичує |
| `nameservers(string ...$hosts): self` | `nameservers[]` — те саме, кілька за раз | накопичує |
| `nameserverWithGlue(string $host, string ...$addresses): self` | `nameservers[]` як `['name' => …, 'addresses' => [...]]` — вбудовані glue-адреси (`<domain:hostAttr>`) | накопичує |
| `authInfo(string $password): self` | `authInfo` — секрет трансферу | замінює |
| `license(string $number): self` | `license` — номер торговельної марки або ліцензії, якщо ваш реєстр його вимагає | замінює |
| `maxFee(string $amount, ?string $currency = null): self` | `fee` — найбільше, що ви згодні заплатити (RFC 8748) | замінює |
| `dsRecord(int $keyTag, int $alg, int $digestType, string $digest): self` | `secDNS['dsData'][]` | накопичує |
| `dsRecordWithKey(int $keyTag, int $alg, int $digestType, string $digest, int $flags, int $protocol, int $keyAlg, string $pubKey): self` | `secDNS['dsData'][]` разом із DNSKEY, з якого його обчислено | накопичує |
| `keyRecord(int $flags, int $protocol, int $alg, string $pubKey): self` | `secDNS['keyData'][]` | накопичує |
| `maxSigLife(int $seconds): self` | `secDNS['maxSigLife']` | замінює |
| `send(): Response` | викликає `domain()->create($name, $options)` | завершальний |

### Реєстрація

```php
use EppTools\Exception\EppException;

try {
    $r = $client->domain()->createBuilder('example.com.ua')
        ->years(1)
        ->registrant('C-0001')
        ->adminContact('C-0001')
        ->techContact('C-0002', 'C-0003')     // одна роль, два ідентифікатори
        ->nameservers('ns1.acme.example', 'ns2.acme.example')
        ->authInfo('D0main-Pw')
        ->maxFee('100.00', 'UAH')
        ->send();

    echo $r->objectName(), ' created ', $r->createdDate(), "\n";
    echo 'expires: ', $r->expiryDate() ?? '-', "\n";
    echo 'charged: ', $r->feeAmount() ?? '-', ' ', $r->feeCurrency() ?? '', "\n";

    if ($r->isPending()) {
        // 1001 — реєстр поставив у чергу. Ще не зареєстровано; вирок прийде через poll.
        $orders->markPending((string) $r->svTRID());
    }
} catch (EppException $e) {
    echo 'EPP error: ', $e->getMessage(), "\n";
}
```

`contact()` приймає будь-яку назву ролі, яку розпізнає реєстр, тож зона з роллю поза
admin/tech/billing доступна без нового методу:

```php
->contact('reseller', 'C-0009')
```

Порожня роль кидає `ValidationException` — `contacts['' => …]` дало б `<domain:contact type="">`, від
чого реєстр відмовляється кодом `2005`, який не називає нічого корисного.

### Дві моделі делегування

```php
// Посилання на об'єкти хостів: спершу створіть самі об'єкти хостів (див. hosts.md).
->nameserver('ns1.acme.example')->nameserver('ns2.acme.example')

// Вбудовані glue-адреси: адреси їдуть разом з іменем. IPv4 і IPv6 розрізняються за самим записом.
->nameserverWithGlue('ns1.example.com.ua', '203.0.113.1', '2001:db8::1')
->nameserverWithGlue('ns2.example.com.ua', '203.0.113.2')
```

RFC 5731 робить `<domain:ns>` вибором між цими двома, тож одна команда використовує одну модель або
другу. Змішування відхиляється ще під час побудови кадру — `ValidationException`, що називає
проблему, а не голий `2001` від реєстру, який не називає жодного поля. Запитайте у свого реєстру, яку
модель він приймає.

`nameserverWithGlue()` з порожнім іменем кидає одразу: сервер імен без імені — не те, що варто
дізнаватися з відповіді.

### DNSSEC під час create

```php
$client->domain()->createBuilder('example.com.ua')
    ->years(1)
    ->registrant('C-0001')
    ->dsRecord(12345, 13, 2, '49FD46E6C4B45C55D4AC69E1F3B2A0D7C8E5904B1A2C3D4E5F60718293A4B5C6')
    ->dsRecord(54321, 13, 2, 'A1B2C3D4E5F60718293A4B5C6D7E8F90112233445566778899AABBCCDDEEFF00')
    ->maxSigLife(1209600)
    ->send();
```

`dsRecordWithKey()` надсилає поруч із записом DS той DNSKEY, з якого обчислено дайджест. Реєстр, який
це приймає, може звірити дайджест із ключем за вас, спіймавши помилку в дайджесті ще до того, як вона
дійде до зони; той, що даних ключа не приймає, відмовить у команді, а не проігнорує зайвий елемент, —
тож спроба коштує не більше ніж `2306`.

```php
->dsRecordWithKey(12345, 13, 2, '49FD46…', flags: 257, protocol: 3, keyAlg: 13, pubKey: 'AwEAA…')
```

`keyRecord()` підписує голим публічним ключем замість запису DS — там, де реєстр такі приймає.
Порожній дайджест або порожній публічний ключ кидають `ValidationException`.

`maxSigLife()` має сенс лише поруч із записом DS або записом ключа.

---

## DomainUpdateBuilder

```php
$client->domain()->updateBuilder(string $name): DomainUpdateBuilder
```

Надсилає `domain:update` (RFC 5731 §3.2.5).

**Оновлення в EPP — це дельта, а не заміна.** Те, чого ви не згадали, лишається точно як було, а *те,
у який блок потрапляє зміна, і є вся семантика команди*:

| Блок | Означає |
|---|---|
| `add` | лишити те, що є, і додати оце |
| `rem` | забрати оце, решту лишити |
| `chg` | замінити це одиничне поле |

Надіслати сервер імен в `add`, коли ви мали на увазі `rem`, — це не збій: це делегування домену на
сервер, який ви намагалися прибрати, і реєстр відповість `1000`. Саме тому кожен крок називає свій
блок. Прочитали префікс методу — прочитали семантику.

| Крок | Блок | Встановлює |
|---|---|---|
| `addNameserver(string $host): self` | `add` | `add['ns'][]` — делегувати ще на один, накопичує |
| `addNameservers(string ...$hosts): self` | `add` | `add['ns'][]` — кілька за раз, накопичує |
| `remNameserver(string $host): self` | `rem` | `rem['ns'][]` — припинити делегування на один, накопичує |
| `remNameservers(string ...$hosts): self` | `rem` | `rem['ns'][]`, накопичує |
| `addContact(string $role, string ...$handles): self` | `add` | `add['contacts'][$role][]`, накопичує |
| `remContact(string $role, string ...$handles): self` | `rem` | `rem['contacts'][$role][]`, накопичує |
| `addStatus(string ...$statuses): self` | `add` | `add['statuses'][]`, накопичує |
| `remStatus(string ...$statuses): self` | `rem` | `rem['statuses'][]`, накопичує |
| `changeRegistrant(string $handle): self` | `chg` | `chg['registrant']`, замінює |
| `changeAuthInfo(string $password): self` | `chg` | `chg['authInfo']` — замінити секрет трансферу, замінює |
| `clearAuthInfo(): self` | `chg` | `chg['clearAuthInfo'] = true` — **прибрати** секрет трансферу |
| `restore(): self` | — | `restore = true` — запит на відновлення RGP (RFC 3915) |
| `license(string $number): self` | — | `license` — номер торговельної марки або ліцензії |
| `maxFee(string $amount, ?string $currency = null): self` | — | `fee` — обмеження, коли зміна тарифікується |
| `addDsRecord(int $keyTag, int $alg, int $digestType, string $digest): self` | `secDNS.add` | `secDNS['add']['dsData'][]`, накопичує |
| `remDsRecord(int $keyTag, int $alg, int $digestType, string $digest): self` | `secDNS.rem` | `secDNS['rem']['dsData'][]`, накопичує |
| `addKeyRecord(int $flags, int $protocol, int $alg, string $pubKey): self` | `secDNS.add` | `secDNS['add']['keyData'][]`, накопичує |
| `remKeyRecord(int $flags, int $protocol, int $alg, string $pubKey): self` | `secDNS.rem` | `secDNS['rem']['keyData'][]`, накопичує |
| `removeAllDnssec(): self` | `secDNS.rem` | `secDNS['remAll'] = true` — зняти підпис із домену цілком |
| `maxSigLife(int $seconds): self` | `secDNS.chg` | `secDNS['maxSigLife']`, замінює |
| `send(): Response` | — | викликає `domain()->update($name, $options)` |

### Зміна делегування

```php
$r = $client->domain()->updateBuilder('example.com.ua')
    ->addNameserver('ns3.acme.example')
    ->remNameserver('ns2.acme.example')
    ->addStatus('clientTransferProhibited')
    ->remStatus('clientHold')
    ->changeRegistrant('C-0009')
    ->send();

echo $r->code(), ' ', $r->message(), "\n";       // 1000 або 1001, коли реєстр ставить у чергу

// Оновлення відповідає результатом, а не об'єктом. Перечитайте новий стан, якщо зберігаєте його:
$after = $client->domain()->info('example.com.ua');
echo implode(', ', $after->nameservers()), "\n";
```

Статуси, які ви можете ставити, — це родина `client*`. Ті, що `server*`, належать реєстру, і спроба
їх торкнутися повертається кодом `2304`.

`changeRegistrant()` — це зміна власника, яку багато реєстрів вважають окремою процедурою з власним
паперовим оформленням: відмова там зазвичай є політикою, а не некоректною командою.

### Відкликання витеклого коду трансферу

```php
// Код потрапив туди, куди не мав:
$client->domain()->updateBuilder('example.com.ua')->clearAuthInfo()->send();

// Пізніше, коли клієнтові знову знадобиться код:
$client->domain()->updateBuilder('example.com.ua')->changeAuthInfo('Fresh-D0main-Pw')->send();
```

`clearAuthInfo()` надсилає `<domain:authInfo><domain:null/></domain:authInfo>`, що **прибирає**
секрет. Це не те саме, що поставити порожній: порожній пароль — це все ще значення, яке власник може
пред'явити, тож домен лишився б рівно настільки ж рухомим, як був. Ці два взаємно виключні — схема не
має способу виразити обидва — тож прохання про обидва кидає `ValidationException` ще до того, як щось
буде надіслано.

### DNSSEC під час update

```php
// Ротація ключа без вікна, у якому домен лишається непідписаним:
$client->domain()->updateBuilder('example.com.ua')
    ->remDsRecord(12345, 13, 2, '49FD46E6C4B45C55D4AC69E1F3B2A0D7C8E5904B1A2C3D4E5F60718293A4B5C6')
    ->addDsRecord(54321, 13, 2, 'A1B2C3D4E5F60718293A4B5C6D7E8F90112233445566778899AABBCCDDEEFF00')
    ->send();

// Зняти підпис цілком:
$client->domain()->updateBuilder('example.com.ua')->removeAllDnssec()->send();

// Замінити весь набір ключів однією операцією:
$client->domain()->updateBuilder('example.com.ua')
    ->removeAllDnssec()
    ->addDsRecord(54321, 13, 2, 'A1B2C3…')
    ->send();
```

Запис, названий у `remDsRecord()`, має збігатися з тим, що тримає реєстр, у **кожному** полі, а не
лише за міткою ключа.

`removeAllDnssec()` і `remDsRecord()`/`remKeyRecord()` взаємно виключні: протокол не вміє виразити
«прибрати все і ще прибрати оце», і кадр, що несе обидва, відхиляється. Білдер відмовляє в такому
поєднанні сам, у якому порядку його не пиши, і повідомленням називає, які саме два кроки конфліктують.

### Відновлення через update-білдер

```php
$client->domain()->updateBuilder('example.com.ua')
    ->restore()
    ->maxFee('1000.00', 'UAH')       // ваше обмеження, а не опублікована ціна
    ->send();
```

Ідентично [`domain()->restore('example.com.ua', '1000.00')`](domains.md#restore). Разом із
відновленням не можуть їхати ні `add`, ні `rem`, ні `chg` — змінюйте домен після, окремою командою.

---

## ContactCreateBuilder

```php
$client->contact()->createBuilder(string $id, string $email): ContactCreateBuilder
```

Надсилає `contact:create` (RFC 5733 §3.2.1).

**Ідентифікатор і пошта — аргументи конструктора, а не кроки**, бо реєстр вимагає обох. Білдер, який
дозволяє забути обов'язкове поле, переносить помилку з вашого редактора на провід.

Передайте `EppTools\Command\Contact::AUTO_ID` як ідентифікатор, щоб реєстр
[сам згенерував хендл](contacts.md#дозволити-реєстру-обрати-ідентифікатор), і прочитайте його назад
через `objectName()`.

| Крок | Встановлює | Накопичує? |
|---|---|---|
| `internationalAddress(string $name, string $city, string $countryCode, array $street = [], ?string $org = null, ?string $stateProvince = null, ?string $postalCode = null): self` | `postalInfos[]` з `type => 'int'` | накопичує |
| `localizedAddress(string $name, string $city, string $countryCode, array $street = [], ?string $org = null, ?string $stateProvince = null, ?string $postalCode = null): self` | `postalInfos[]` з `type => 'loc'` | накопичує |
| `voice(string $number): self` | `voice` — форма EPP `+CC.NNNNNNNNN`, за потреби `x` і додатковий номер | замінює |
| `fax(string $number): self` | `fax` — та сама форма | замінює |
| `authInfo(string $password): self` | `authInfo` — секрет трансферу контакту | замінює |
| `publish(string ...$fields): self` | `disclose` з `flag => true` — згода публікувати оці | замінює |
| `withhold(string ...$fields): self` | `disclose` з `flag => false` — приховати оці | замінює |
| `send(): Response` | викликає `contact()->create($id, $options)` | завершальний |

```php
$r = $client->contact()->createBuilder('C-0001', 'contact@example.com')
    ->internationalAddress(
        name: 'Ivan Petrenko',
        city: 'Kyiv',
        countryCode: 'UA',
        street: ['1 Khreschatyk St'],
        org: 'ACME LLC',
        postalCode: '01001',
    )
    ->localizedAddress(
        name: 'Іван Петренко',
        city: 'Київ',
        countryCode: 'UA',
        street: ['вул. Хрещатик 1'],
        org: 'ТОВ «АКМЕ»',
        postalCode: '01001',
    )
    ->voice('+380.441234567')
    ->authInfo('C0nt@ct-Pw')
    ->withhold('voice', 'email')
    ->send();

echo $r->objectName(), "\n";      // 'C-0001' — ідентифікатор
```

Іменовані аргументи пасують цим двом крокам: усі чотири необов'язкові параметри є рядками, а
`internationalAddress('ACME', 'Kyiv', 'UA', [], 'ACME LLC', null, '01001')` — це ряд значень, сенс
яких доводиться відлічувати.

Потрібна щонайменше одна поштова форма. Давайте `internationalAddress()`, якщо у вас немає причини
вчинити інакше: це та форма, що переживає друк, пересилання поштою і читання системою, яка не знає
кирилиці, а кирилиця всередині блоку `int` відхиляється кодом `2005`. Локалізована форма є
додатковою, а не альтернативною — надсилайте обидві, коли маєте обидві, і нічого не буде втрачено.

### publish і withhold

Розкриття даних за RFC 5733. Назви полів — `name`, `org`, `addr`, `voice`, `fax` і `email`; будь-що
інше кидає `ValidationException`, називаючи ці шість.

```php
->withhold('voice', 'email')     // ці приховані; до всього іншого застосовується протилежне
->publish('name', 'org')         // ці можна публікувати; усе інше приховується
```

**Це два способи сказати одне й те саме, і другий виклик замінює перший.** Оберіть той, що збігається
з тим, як ви думаєте про це налаштування, і не викликайте обидва: прапорець і є весь сенс списку, тож
блок, зібраний з двох половин, скаже те, чого не мав на увазі жоден із викликів.

`name`, `org` і `addr` існують по одному на кожну поштову форму, тож згадка будь-якого з них покриває
**обидві** форми. Приховати лише форму ASCII, лишивши локальну публічною, було б налаштуванням
приватності, яке читається як застосоване і таким не є.

---

## ContactUpdateBuilder

```php
$client->contact()->updateBuilder(string $id): ContactUpdateBuilder
```

Надсилає `contact:update` (RFC 5733 §3.2.5). Те, чого ви не згадали, лишається недоторканим.

| Крок | Блок | Встановлює |
|---|---|---|
| `changeInternationalAddress(?string $name = null, ?string $city = null, ?string $countryCode = null, ?array $street = null, ?string $org = null, ?string $stateProvince = null, ?string $postalCode = null): self` | `chg` | `chg['postalInfos'][]` з `type => 'int'` — лише ті аргументи, які ви передали |
| `changeLocalizedAddress(…ті самі параметри…): self` | `chg` | `chg['postalInfos'][]` з `type => 'loc'` |
| `changeVoice(string $number): self` | `chg` | `chg['voice']` |
| `changeFax(string $number): self` | `chg` | `chg['fax']` |
| `changeEmail(string $email): self` | `chg` | `chg['email']` |
| `changeAuthInfo(string $password): self` | `chg` | `chg['authInfo']` — замінити секрет трансферу |
| `publish(string ...$fields): self` | `chg` | `chg['disclose']` з `flag => true` |
| `withhold(string ...$fields): self` | `chg` | `chg['disclose']` з `flag => false` |
| `addStatus(string ...$statuses): self` | `add` | `addStatuses[]`, накопичує |
| `remStatus(string ...$statuses): self` | `rem` | `remStatuses[]`, накопичує |
| `send(): Response` | — | викликає `contact()->update($id, $options)` |

```php
$client->contact()->updateBuilder('C-0001')
    ->changeEmail('new-contact@example.com')
    ->changeVoice('+380.441234500')
    ->addStatus('clientUpdateProhibited')
    ->send();
```

### Усередині адреси вирішує наявність

Три стани різні, і саме ними виражається часткова зміна адреси:

| Що ви пишете | Що відбувається |
|---|---|
| пропустити аргумент або передати `null` | поле не надсилається, і реєстр зберігає своє значення |
| передати значення | поле встановлюється в нього |
| передати `''` | поле **очищується** — єдиний спосіб прибрати `org`, `stateProvince` чи `postalCode` |

```php
// Перенести контакт до Львова, очистити організацію, лишити ім'я та вулицю як є.
$client->contact()->updateBuilder('C-0001')
    ->changeInternationalAddress(city: 'Lviv', countryCode: 'UA', org: '')
    ->send();
```

**Передавайте `city` і `countryCode` щоразу, коли торкаєтеся адреси.** `<contact:addr>` — це схемна
послідовність, у якій обидва обов'язкові, тож він видається цілим або не видається зовсім: щойно ви
згадали `street`, `city`, `stateProvince`, `postalCode` чи `countryCode`, увесь блок іде на провід, і
ці двоє їдуть з ним, чи ви їх дали, чи ні. Пропустити їх там означає надіслати їх порожніми.

Форма, якої ви не згадали — локальна чи міжнародна, — лишається недоторканою.

### Тут немає clearAuthInfo()

RFC 5731 дає доменові форму, що допускає порожнє значення, — `<domain:authInfo><domain:null/>`; RFC
5733 не визначає рівноцінної для контакту. Тож секрет трансферу контакту можна **замінити, але не
прибрати**. Не тягніться до порожнього пароля як до заміни: порожнє значення — це все ще значення,
яке власник може пред'явити. Ставте натомість свіжий секрет через `changeAuthInfo()`.

---

## HostUpdateBuilder

```php
$client->host()->updateBuilder(string $name): HostUpdateBuilder
```

Надсилає `host:update` (RFC 5732 §3.2.5).

| Крок | Блок | Встановлює |
|---|---|---|
| `addAddress(string $ip): self` | `add` | `addAddresses[]` — одна glue-адреса, накопичує |
| `addAddresses(string ...$ips): self` | `add` | `addAddresses[]` — кілька, накопичує |
| `remAddress(string $ip): self` | `rem` | `remAddresses[]`, накопичує |
| `remAddresses(string ...$ips): self` | `rem` | `remAddresses[]`, накопичує |
| `addStatus(string ...$statuses): self` | `add` | `addStatuses[]`, накопичує |
| `remStatus(string ...$statuses): self` | `rem` | `remStatuses[]`, накопичує |
| `send(): Response` | — | викликає `host()->update($name, $options)` |

```php
$client->host()->updateBuilder('ns1.example.com.ua')
    ->addAddresses('192.0.2.10', '2001:db8::10')
    ->remAddress('192.0.2.9')
    ->send();
```

IPv4 і IPv6 розрізняються за самим записом, тож `v4` і `v6` підписуються правильно без того, щоб ви
казали, де що.

**Кроку перейменування немає.** Реєстр не реалізує `host:chg`, тож перейменування — не те, що цей
білдер здатен виразити; див. [Хости](hosts.md#перейменування-не-існує), де описані три команди, які роблять
цю роботу натомість.

Додавання і прибирання тієї самої адреси в одній команді — це суперечність, яку реєстр розв'язує так,
як сам вирішить. Надсилайте одне або друге.

---

## Чого білдер не змінює

Білдер — це фасад над масивом опцій, тож усе, чому підпорядкований масив, лишається чинним:

- **Та сама валідація.** `send()` викликає звичайний метод, який перевіряє свої опції рівно так, як
  зробив би це для написаного руками масиву. Білдер не здатен породити невідомий ключ, але здатен
  породити поєднання, від якого команда відмовиться.
- **Ті самі коди відповіді.** `2302`, `2104`, `1001` означають те, що означають; див.
  [Помилки](errors.md).
- **Та сама поведінка `throwOnFailure`.** З вимкненим киданням `send()` повертає відмову як
  `Response`, а не піднімає виняток.
- **Те саме поводження з секретами.** `authInfo()` встановлює живий секрет доступу. У власних журналах
  бібліотеки він маскується — але `toOptions()` це вже ваш масив, і якщо ви пишете його в журнал чи
  кладете в чергу, він несе пароль відкритим текстом. Маскуйте його самі, перш ніж він дійде до
  журналу.

## Які кроки кидають ще до відправки

Усе це — `ValidationException`, і в кожному випадку жодного кадру не було збудовано:

| Крок | Кидає, коли |
|---|---|
| будь-який `contact(…)` / `addContact(…)` / `remContact(…)` | роль порожня або складається з пробілів |
| `nameserverWithGlue(…)` | ім'я сервера імен порожнє |
| `dsRecord(…)`, `dsRecordWithKey(…)`, `addDsRecord(…)`, `remDsRecord(…)` | дайджест порожній або складається з пробілів |
| `keyRecord(…)`, `addKeyRecord(…)`, `remKeyRecord(…)`, `dsRecordWithKey(…)` | публічний ключ порожній або складається з пробілів |
| `removeAllDnssec()` після `remDsRecord()`/`remKeyRecord()`, або будь-який із них після нього | ці два взаємно виключні |
| `maxFee(…)` | сума не є звичайним десятковим числом на кшталт `100.00` |
| `publish(…)` / `withhold(…)` | поле не є одним із name, org, addr, voice, fax, email |
| `send()` | білдер уже було надіслано |

Некоректна згода на ціну перевіряється тут, а не на проводі, бо інакше вона тягне голий `2001`, який
не називає жодного поля, — і приходить уже після того, як команду спробували виконати.

---

## Коли масив є кращим інструментом

Білдери й масиви — це одне й те саме, тож користуйтеся тим, що пасує:

- Збірка з конфігураційного файла, рядка бази даних чи корисного навантаження з черги, яке вже є
  масивом: передавайте його прямо в `create()`/`update()`. Перетворювати його на ланцюжок викликів
  лише для того, щоб білдер перетворив його назад на масив, не додає нічого.
- Написання команди прямо в коді, особливо оновлення: беріть білдер. `->remStatus('clientHold')` каже,
  у який блок потрапляє зміна, у місці, де помилитися неможливо.

`toOptions()` — місток між цими двома, і він працює в обидва боки: збирайте плинним API, зберігайте
масив, програвайте його потім прямим викликом.

---

Див. також: [Домени](domains.md) · [Контакти](contacts.md) · [Хости](hosts.md) ·
[Баланс і ціни](balance.md) · [Відповіді](responses.md) · [Помилки](errors.md)

[← Зміст мануала](README.md)
