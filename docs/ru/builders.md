# Билдеры

Команды, принимающие массив опций, можно собрать и по одному именованному шагу за раз.

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

**Та же команда, тот же кадр, тот же результат.** Билдер не строит собственного XML: `send()`
передаёт свои опции обычному методу, поэтому билдер и равнозначный массив дают одинаковый кадр, и
каждая проверка, применимая к одному, применима и к другому.

Меняется то, где всплывает ошибка. Массив опций принимает любой ключ, поэтому `'yeras' => 1`
отлавливается лишь потому, что библиотеке известен весь список ключей и она отвергает всё, чего в нём
нет. В билдере ошибаться не в чем: `->yeras(1)` — несуществующий метод, и ваш редактор скажет об этом
прямо при наборе.

Всё здесь предполагает подключённый клиент с выполненным входом — см. [Сессию](session.md).

## Пять билдеров

| Класс | Откуда берётся | Что отправляет |
|---|---|---|
| `DomainCreateBuilder` | `$client->domain()->createBuilder(string $name)` | `domain:create` |
| `DomainUpdateBuilder` | `$client->domain()->updateBuilder(string $name)` | `domain:update` |
| `ContactCreateBuilder` | `$client->contact()->createBuilder(string $id, string $email)` | `contact:create` |
| `ContactUpdateBuilder` | `$client->contact()->updateBuilder(string $id)` | `contact:update` |
| `HostUpdateBuilder` | `$client->host()->updateBuilder(string $name)` | `host:update` |

Они живут в `EppTools\Builder\`. Напрямую вы их не создаёте — это делает обработчик, поэтому билдер
уже знает, через какой клиент отправлять.

Для `check`, `info`, `renew`, `transfer`, `delete` и `restore` билдера намеренно нет. Они принимают
позиционные аргументы, которые язык и так проверяет; билдер добавил бы церемоний, не убрав ни одного
класса ошибок.

---

## Четыре правила, действующие для каждого билдера

### 1. Каждый списочный шаг накапливает

Передать несколько сразу, вызвать шаг ещё раз или и то и другое — одно и то же:

```php
->techContact('C-0002', 'C-0003')
->techContact('C-0002')->techContact('C-0003')   // одно и то же
```

Именно поэтому билдер читается так же, как ведёт себя в цикле или за условием:

```php
$builder = $client->domain()->createBuilder('example.com.ua')->years(1)->registrant('C-0001');

foreach ($nameservers as $host) {
    $builder->nameserver($host);        // каждый вызов добавляет один
}
if ($needsDnssec) {
    $builder->dsRecord(12345, 13, 2, $digest);
}

$builder->send();
```

Одиночные шаги вместо этого **заменяют**: `->years(1)->years(2)` оставит `2`, как двойное
присваивание переменной. Какой шаг к чему относится, сказано в таблицах ниже.

Пустые значения и значения из одних пробелов списочные шаги отбрасывают, а не отправляют пустыми
элементами, поэтому цикл по списку с пропуском внутри не выдаст `<domain:hostObj/>`.

### 2. Ничего не отправляется до `send()`

До этого билдер — обычное значение. Храните его, передавайте в другую функцию, собирайте в одном
месте, а отправляйте в другом.

```php
$builder = $client->domain()->createBuilder('example.com.ua')->years(1)->registrant('C-0001');
// …до реестра ничего не дошло…
$response = $builder->send();     // а теперь дошло
```

`send()` возвращает [`Response`](responses.md) — ровно так же, как и прямой вызов.

### 3. `toOptions()` отдаёт ровно то, что принимает прямой вызов, — копией

```php
public function toOptions(): array
```

Есть у каждого билдера.

```php
$builder = $client->domain()->createBuilder('example.com.ua')
    ->years(1)
    ->registrant('C-0001')
    ->techContact('C-0002');

$builder->toOptions();
// ['years' => 1, 'registrant' => 'C-0001', 'contacts' => ['tech' => ['C-0002']]]

// Значит, это та же команда, только другой дорогой:
$client->domain()->create('example.com.ua', $builder->toOptions());
```

Важны два свойства:

- **Это ровно тот массив, который принимает прямой метод.** Благодаря этому билдер можно ставить в
  очередь: сериализуйте `toOptions()`, положите в очередь, и пусть рабочий процесс вызовет с ним
  `create()`.
- **Это глубокая копия.** Отдавать живой массив значило бы позволить ему меняться под вызывающим при
  каждом новом шаге, и тогда записанное в журнал и отправленное могли бы разойтись. Вы получаете
  законченное значение.

Вызов ничего не отправляет и не расходует билдер.

### 4. Билдер отправляет один раз

```php
$builder = $client->domain()->createBuilder('example.com.ua')->years(1)->registrant('C-0001');

$builder->send();
$builder->send();
// ValidationException: EppTools\Builder\DomainCreateBuilder has already been sent.
//                      A builder carries one command; build another rather than re-sending this one.
```

Второй `send()` на создании — это вторая регистрация и второе списание, и вызывающий никогда не имел
этого в виду: повтор после сбоя — не то же самое, что переигрывание объекта, который уже ушёл.
Соберите новый билдер, они ничего не стоят. Если первый `send()` упал так, что результат остался
неизвестным, прочитайте
[Ошибки](errors.md#когда-трансформирующая-команда-упала-и-вы-не-знаете-выполнилась-ли-она), прежде чем
делать хоть что-нибудь.

---

## DomainCreateBuilder

```php
$client->domain()->createBuilder(string $name): DomainCreateBuilder
```

Отправляет `domain:create` (RFC 5731 §3.2.1). У каждой опции
[`domain()->create()`](domains.md#create) здесь есть свой шаг.

| Шаг | Что задаёт | Накапливает? |
|---|---|---|
| `years(int $years): self` | `years` — `<domain:period unit="y">`. Не задавайте его — и реестр применит свой срок по умолчанию | заменяет |
| `registrant(string $handle): self` | `registrant` — держатель домена | заменяет |
| `contact(string $role, string ...$handles): self` | `contacts[$role][]` — по одному `<domain:contact type="…">` на идентификатор | накапливает |
| `adminContact(string ...$handles): self` | `contacts['admin'][]` | накапливает |
| `techContact(string ...$handles): self` | `contacts['tech'][]` | накапливает |
| `billingContact(string ...$handles): self` | `contacts['billing'][]` | накапливает |
| `nameserver(string $host): self` | `nameservers[]` — одна ссылка на объект хоста (`<domain:hostObj>`) | накапливает |
| `nameservers(string ...$hosts): self` | `nameservers[]` — то же самое, по нескольку за раз | накапливает |
| `nameserverWithGlue(string $host, string ...$addresses): self` | `nameservers[]` в виде `['name' => …, 'addresses' => [...]]` — встроенные glue-адреса (`<domain:hostAttr>`) | накапливает |
| `authInfo(string $password): self` | `authInfo` — код трансфера | заменяет |
| `license(string $number): self` | `license` — номер товарного знака или лицензии, если ваш реестр его требует | заменяет |
| `maxFee(string $amount, ?string $currency = null): self` | `fee` — максимум, который вы согласны заплатить (RFC 8748) | заменяет |
| `dsRecord(int $keyTag, int $alg, int $digestType, string $digest): self` | `secDNS['dsData'][]` | накапливает |
| `dsRecordWithKey(int $keyTag, int $alg, int $digestType, string $digest, int $flags, int $protocol, int $keyAlg, string $pubKey): self` | `secDNS['dsData'][]` вместе с DNSKEY, из которого он вычислен | накапливает |
| `keyRecord(int $flags, int $protocol, int $alg, string $pubKey): self` | `secDNS['keyData'][]` | накапливает |
| `maxSigLife(int $seconds): self` | `secDNS['maxSigLife']` | заменяет |
| `send(): Response` | вызывает `domain()->create($name, $options)` | терминальный |

### Регистрация

```php
use EppTools\Exception\EppException;

try {
    $r = $client->domain()->createBuilder('example.com.ua')
        ->years(1)
        ->registrant('C-0001')
        ->adminContact('C-0001')
        ->techContact('C-0002', 'C-0003')     // одна роль, два идентификатора
        ->nameservers('ns1.acme.example', 'ns2.acme.example')
        ->authInfo('D0main-Pw')
        ->maxFee('100.00', 'UAH')
        ->send();

    echo $r->objectName(), ' created ', $r->createdDate(), "\n";
    echo 'expires: ', $r->expiryDate() ?? '-', "\n";
    echo 'charged: ', $r->feeAmount() ?? '-', ' ', $r->feeCurrency() ?? '', "\n";

    if ($r->isPending()) {
        // 1001 — реестр поставил её в очередь. Ещё не зарегистрировано; вердикт придёт через poll.
        $orders->markPending((string) $r->svTRID());
    }
} catch (EppException $e) {
    echo 'EPP error: ', $e->getMessage(), "\n";
}
```

`contact()` принимает любое имя роли, которое распознаёт реестр, поэтому зона с ролью помимо
admin/tech/billing достижима без нового метода:

```php
->contact('reseller', 'C-0009')
```

Пустая роль вызывает `ValidationException`: `contacts['' => …]` дало бы
`<domain:contact type="">`, что реестр отклоняет кодом `2005`, не называя ничего полезного.

### Две модели делегирования

```php
// Ссылки на объекты хостов: сначала создайте сами объекты хостов (см. hosts.md).
->nameserver('ns1.acme.example')->nameserver('ns2.acme.example')

// Встроенные glue-адреса: адреса едут вместе с именем. IPv4 и IPv6 различаются по самому литералу.
->nameserverWithGlue('ns1.example.com.ua', '203.0.113.1', '2001:db8::1')
->nameserverWithGlue('ns2.example.com.ua', '203.0.113.2')
```

RFC 5731 делает `<domain:ns>` выбором между двумя моделями, поэтому одна команда использует либо
одну, либо другую. Смешение отклоняется при сборке кадра — `ValidationException` называет проблему,
вместо голого `2001` от реестра, который не называет ни одного поля. Спросите у своего реестра,
какую модель он принимает.

`nameserverWithGlue()` с пустым именем выбрасывает исключение сразу: сервер имён без имени — не то,
о чём стоит узнавать из ответа.

### DNSSEC при создании

```php
$client->domain()->createBuilder('example.com.ua')
    ->years(1)
    ->registrant('C-0001')
    ->dsRecord(12345, 13, 2, '49FD46E6C4B45C55D4AC69E1F3B2A0D7C8E5904B1A2C3D4E5F60718293A4B5C6')
    ->dsRecord(54321, 13, 2, 'A1B2C3D4E5F60718293A4B5C6D7E8F90112233445566778899AABBCCDDEEFF00')
    ->maxSigLife(1209600)
    ->send();
```

`dsRecordWithKey()` отправляет вместе с DS-записью тот DNSKEY, из которого вычислен дайджест.
Реестр, который это принимает, может сверить дайджест с ключом за вас и поймать опечатку в дайджесте
до того, как она дойдёт до зоны; реестр, который данные ключа не принимает, отклоняет команду, а не
игнорирует лишний элемент, так что попытка не стоит ничего, кроме `2306`.

```php
->dsRecordWithKey(12345, 13, 2, '49FD46…', flags: 257, protocol: 3, keyAlg: 13, pubKey: 'AwEAA…')
```

`keyRecord()` подписывает голым открытым ключом вместо DS-записи — там, где реестр такие принимает.
Пустой дайджест или пустой открытый ключ вызывает `ValidationException`.

`maxSigLife()` имеет смысл только рядом с DS-записью или записью ключа.

---

## DomainUpdateBuilder

```php
$client->domain()->updateBuilder(string $name): DomainUpdateBuilder
```

Отправляет `domain:update` (RFC 5731 §3.2.5).

**Обновление EPP — это дельта, а не замена.** То, чего вы не упомянули, остаётся ровно таким, каким
было, а *то, в какой блок попадает изменение, и есть вся семантика команды*:

| Блок | Что означает |
|---|---|
| `add` | оставить то, что есть, и добавить это |
| `rem` | убрать это, остальное оставить |
| `chg` | заменить это одиночное поле |

Отправить сервер имён в `add`, имея в виду `rem`, — это не сбой: домен делегируется тому серверу,
который вы пытались убрать, а реестр отвечает `1000`. Поэтому каждый шаг называет свой блок.
Прочитали префикс метода — прочитали семантику.

| Шаг | Блок | Что задаёт |
|---|---|---|
| `addNameserver(string $host): self` | `add` | `add['ns'][]` — делегировать ещё одному, накапливает |
| `addNameservers(string ...$hosts): self` | `add` | `add['ns'][]` — по нескольку за раз, накапливает |
| `remNameserver(string $host): self` | `rem` | `rem['ns'][]` — перестать делегировать одному, накапливает |
| `remNameservers(string ...$hosts): self` | `rem` | `rem['ns'][]`, накапливает |
| `addContact(string $role, string ...$handles): self` | `add` | `add['contacts'][$role][]`, накапливает |
| `remContact(string $role, string ...$handles): self` | `rem` | `rem['contacts'][$role][]`, накапливает |
| `addStatus(string ...$statuses): self` | `add` | `add['statuses'][]`, накапливает |
| `remStatus(string ...$statuses): self` | `rem` | `rem['statuses'][]`, накапливает |
| `changeRegistrant(string $handle): self` | `chg` | `chg['registrant']`, заменяет |
| `changeAuthInfo(string $password): self` | `chg` | `chg['authInfo']` — заменить код трансфера, заменяет |
| `clearAuthInfo(): self` | `chg` | `chg['clearAuthInfo'] = true` — **удалить** код трансфера |
| `restore(): self` | — | `restore = true` — запрос восстановления RGP (RFC 3915) |
| `license(string $number): self` | — | `license` — номер товарного знака или лицензии |
| `maxFee(string $amount, ?string $currency = null): self` | — | `fee` — ограничение суммы, когда изменение тарифицируется |
| `addDsRecord(int $keyTag, int $alg, int $digestType, string $digest): self` | `secDNS.add` | `secDNS['add']['dsData'][]`, накапливает |
| `remDsRecord(int $keyTag, int $alg, int $digestType, string $digest): self` | `secDNS.rem` | `secDNS['rem']['dsData'][]`, накапливает |
| `addKeyRecord(int $flags, int $protocol, int $alg, string $pubKey): self` | `secDNS.add` | `secDNS['add']['keyData'][]`, накапливает |
| `remKeyRecord(int $flags, int $protocol, int $alg, string $pubKey): self` | `secDNS.rem` | `secDNS['rem']['keyData'][]`, накапливает |
| `removeAllDnssec(): self` | `secDNS.rem` | `secDNS['remAll'] = true` — полностью снять подпись с домена |
| `maxSigLife(int $seconds): self` | `secDNS.chg` | `secDNS['maxSigLife']`, заменяет |
| `send(): Response` | — | вызывает `domain()->update($name, $options)` |

### Смена делегирования

```php
$r = $client->domain()->updateBuilder('example.com.ua')
    ->addNameserver('ns3.acme.example')
    ->remNameserver('ns2.acme.example')
    ->addStatus('clientTransferProhibited')
    ->remStatus('clientHold')
    ->changeRegistrant('C-0009')
    ->send();

echo $r->code(), ' ', $r->message(), "\n";       // 1000 или 1001, если реестр ставит в очередь

// Обновление отвечает результатом, а не объектом. Если храните состояние, перечитайте новое:
$after = $client->domain()->info('example.com.ua');
echo implode(', ', $after->nameservers()), "\n";
```

Устанавливать вы можете статусы семейства `client*`. Статусы `server*` принадлежат реестру, и попытка
тронуть их возвращается кодом `2304`.

`changeRegistrant()` — это смена держателя, которую многие реестры считают отдельной процедурой со
своим документооборотом; отказ здесь обычно означает политику, а не неправильно составленную
команду.

### Отзыв утёкшего кода трансфера

```php
// Код ушёл туда, куда не должен был:
$client->domain()->updateBuilder('example.com.ua')->clearAuthInfo()->send();

// Позже, когда клиенту снова понадобится код:
$client->domain()->updateBuilder('example.com.ua')->changeAuthInfo('Fresh-D0main-Pw')->send();
```

`clearAuthInfo()` отправляет `<domain:authInfo><domain:null/></domain:authInfo>`, что **удаляет**
код. Это не то же самое, что задать пустой: пустой пароль — всё ещё значение, которое держатель может
предъявить, так что домен остался бы ровно настолько же переносимым, насколько был. Эти два варианта
взаимоисключающие — схема не умеет выразить оба сразу, — поэтому запрос обоих вызывает
`ValidationException` до того, как что-либо будет отправлено.

### DNSSEC при обновлении

```php
// Сменить ключ без промежутка, в котором домен остаётся неподписанным:
$client->domain()->updateBuilder('example.com.ua')
    ->remDsRecord(12345, 13, 2, '49FD46E6C4B45C55D4AC69E1F3B2A0D7C8E5904B1A2C3D4E5F60718293A4B5C6')
    ->addDsRecord(54321, 13, 2, 'A1B2C3D4E5F60718293A4B5C6D7E8F90112233445566778899AABBCCDDEEFF00')
    ->send();

// Полностью снять подпись:
$client->domain()->updateBuilder('example.com.ua')->removeAllDnssec()->send();

// Заменить весь набор ключей за одну операцию:
$client->domain()->updateBuilder('example.com.ua')
    ->removeAllDnssec()
    ->addDsRecord(54321, 13, 2, 'A1B2C3…')
    ->send();
```

Запись, названная в `remDsRecord()`, должна совпадать с тем, что хранит реестр, по **каждому** полю,
а не только по key tag.

`removeAllDnssec()` и `remDsRecord()`/`remKeyRecord()` взаимоисключающие: протокол не умеет выразить
«удалить всё и заодно удалить вот это», и кадр, несущий оба указания, отклоняется. Билдер отвергает
такое сочетание сам, в каком бы порядке вы его ни написали, сообщением о том, какие именно два шага
конфликтуют.

### Восстановление через билдер обновления

```php
$client->domain()->updateBuilder('example.com.ua')
    ->restore()
    ->maxFee('1000.00', 'UAH')       // ваше ограничение, а не опубликованная цена
    ->send();
```

Идентично [`domain()->restore('example.com.ua', '1000.00')`](domains.md#restore). Никакие `add`,
`rem` или `chg` не могут ехать вместе с восстановлением — меняйте домен потом, второй командой.

---

## ContactCreateBuilder

```php
$client->contact()->createBuilder(string $id, string $email): ContactCreateBuilder
```

Отправляет `contact:create` (RFC 5733 §3.2.1).

**Идентификатор и адрес электронной почты — аргументы конструктора, а не шаги**, потому что реестр
требует и то и другое. Билдер, позволяющий забыть обязательное поле, переносит ошибку из вашего
редактора на провод.

Передайте в качестве идентификатора `EppTools\Command\Contact::AUTO_ID`, чтобы
[идентификатор выдал реестр](contacts.md#позволить-реестру-выбрать-идентификатор), и прочитайте его
через `objectName()`.

| Шаг | Что задаёт | Накапливает? |
|---|---|---|
| `internationalAddress(string $name, string $city, string $countryCode, array $street = [], ?string $org = null, ?string $stateProvince = null, ?string $postalCode = null): self` | `postalInfos[]` с `type => 'int'` | накапливает |
| `localizedAddress(string $name, string $city, string $countryCode, array $street = [], ?string $org = null, ?string $stateProvince = null, ?string $postalCode = null): self` | `postalInfos[]` с `type => 'loc'` | накапливает |
| `voice(string $number): self` | `voice` — форма EPP `+CC.NNNNNNNNN`, при необходимости с `x` и добавочным номером | заменяет |
| `fax(string $number): self` | `fax` — та же форма | заменяет |
| `authInfo(string $password): self` | `authInfo` — код трансфера контакта | заменяет |
| `publish(string ...$fields): self` | `disclose` с `flag => true` — согласие публиковать перечисленное | заменяет |
| `withhold(string ...$fields): self` | `disclose` с `flag => false` — скрывать перечисленное | заменяет |
| `send(): Response` | вызывает `contact()->create($id, $options)` | терминальный |

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

echo $r->objectName(), "\n";      // 'C-0001' — идентификатор
```

Именованные аргументы этим двум шагам подходят как нельзя лучше: четыре необязательных параметра —
все строки, а `internationalAddress('ACME', 'Kyiv', 'UA', [], 'ACME LLC', null, '01001')` — это ряд
значений, смысл которых приходится отсчитывать по позициям.

Нужна хотя бы одна форма адреса. Указывайте `internationalAddress()`, если нет причин поступить
иначе: это та форма, которая переживает печать, пересылку по почте и чтение системой, не знающей
кириллицы, а кириллица внутри блока `int` отклоняется кодом `2005`. Локализованная форма —
дополнение, а не альтернатива: присылайте обе, если у вас есть обе, ничего не отбрасывается.

### publish и withhold

Раскрытие по RFC 5733. Имена полей — `name`, `org`, `addr`, `voice`, `fax` и `email`; всё остальное
вызывает `ValidationException` с перечислением этих шести.

```php
->withhold('voice', 'email')     // эти скрыты; со всем остальным поступают наоборот
->publish('name', 'org')         // эти можно публиковать; всё остальное скрыто
```

**Это два способа сказать одно и то же, и второй вызов заменяет первый.** Выберите тот, который
совпадает с тем, как вы думаете об этом предпочтении, и не вызывайте оба: флаг — это весь смысл
списка, поэтому блок, собранный из двух половин, скажет то, чего не имел в виду ни один из вызовов.

`name`, `org` и `addr` существуют по одному на каждую почтовую форму, поэтому указание любого из них
покрывает **обе** формы. Скрыть только ASCII-форму, оставив локальную публичной, — это настройка
приватности, которая читается как применённая, но таковой не является.

---

## ContactUpdateBuilder

```php
$client->contact()->updateBuilder(string $id): ContactUpdateBuilder
```

Отправляет `contact:update` (RFC 5733 §3.2.5). То, чего вы не упомянули, остаётся нетронутым.

| Шаг | Блок | Что задаёт |
|---|---|---|
| `changeInternationalAddress(?string $name = null, ?string $city = null, ?string $countryCode = null, ?array $street = null, ?string $org = null, ?string $stateProvince = null, ?string $postalCode = null): self` | `chg` | `chg['postalInfos'][]` с `type => 'int'` — только те аргументы, которые вы передали |
| `changeLocalizedAddress(…те же параметры…): self` | `chg` | `chg['postalInfos'][]` с `type => 'loc'` |
| `changeVoice(string $number): self` | `chg` | `chg['voice']` |
| `changeFax(string $number): self` | `chg` | `chg['fax']` |
| `changeEmail(string $email): self` | `chg` | `chg['email']` |
| `changeAuthInfo(string $password): self` | `chg` | `chg['authInfo']` — заменить код трансфера |
| `publish(string ...$fields): self` | `chg` | `chg['disclose']` с `flag => true` |
| `withhold(string ...$fields): self` | `chg` | `chg['disclose']` с `flag => false` |
| `addStatus(string ...$statuses): self` | `add` | `addStatuses[]`, накапливает |
| `remStatus(string ...$statuses): self` | `rem` | `remStatuses[]`, накапливает |
| `send(): Response` | — | вызывает `contact()->update($id, $options)` |

```php
$client->contact()->updateBuilder('C-0001')
    ->changeEmail('new-contact@example.com')
    ->changeVoice('+380.441234500')
    ->addStatus('clientUpdateProhibited')
    ->send();
```

### Адрес ЗАМЕЩАЕТСЯ целиком, а не сливается по полям

Блок, который вы передаёте, **замещает** тот, что хранит реестр. Их не сливают поле за полем,
поэтому всё, чего вы не передали, исчезает:

| Что вы пишете | Что происходит |
|---|---|
| передаёте значение | поле принимает это значение |
| передаёте `''` | поле **очищается** — так убирают `org`, `stateProvince` или `postalCode` |
| не передаёте аргумент или передаёте `null` | поле не отправляется — и реестр удаляет то, что хранил |

RFC 5733 можно прочитать как «не передавайте — и реестр сохранит своё значение», ведь каждая
составляющая `chgPostalInfoType` необязательна, но это чтение небезопасно. Против реестра, который
замещает блок, — причём **каждая команда отвечает 1000** — блок, отправленный без `org`,
возвращается уже без организации, а блок, в котором был один лишь `org`, оставляет контакт вообще
без почтового адреса: без имени, улицы, города, индекса и страны.

Именно поэтому `name`, `city` и `countryCode` обязательны в любом изменении адреса, и билдер без них
отказывает. Они удерживают кадр валидным, но вернуть поле, которого вы не передали, не могут.
**Сначала прочитайте блок и верните его вместе со своим изменением:**

```php
$current = $client->contact()->info('C-0001')->postalInfo()['int'];

// Переселить контакт во Львов и очистить организацию, оставив остальное таким, каким оно было.
$client->contact()->updateBuilder('C-0001')
    ->changeInternationalAddress(
        name: $current['name'],
        city: 'Lviv',
        countryCode: 'UA',
        street: $current['street'] ?? null,
        org: '',
    )
    ->send();
```

Форма, которую вы не упомянули, — локальная или международная — остаётся нетронутой: они
адресуются раздельно.

### Здесь нет clearAuthInfo()

RFC 5731 даёт домену обнуляемую форму `<domain:authInfo><domain:null/>`; для контакта RFC 5733
ничего равнозначного не определяет. Поэтому код трансфера контакта можно **заменить, но не
удалить**. Не хватайтесь взамен за пустой пароль: пустое значение — всё ещё значение, которое
держатель может предъявить. Вместо этого задайте новый код через `changeAuthInfo()`.

---

## HostUpdateBuilder

```php
$client->host()->updateBuilder(string $name): HostUpdateBuilder
```

Отправляет `host:update` (RFC 5732 §3.2.5).

| Шаг | Блок | Что задаёт |
|---|---|---|
| `addAddress(string $ip): self` | `add` | `addAddresses[]` — один glue-адрес, накапливает |
| `addAddresses(string ...$ips): self` | `add` | `addAddresses[]` — несколько, накапливает |
| `remAddress(string $ip): self` | `rem` | `remAddresses[]`, накапливает |
| `remAddresses(string ...$ips): self` | `rem` | `remAddresses[]`, накапливает |
| `addStatus(string ...$statuses): self` | `add` | `addStatuses[]`, накапливает |
| `remStatus(string ...$statuses): self` | `rem` | `remStatuses[]`, накапливает |
| `send(): Response` | — | вызывает `host()->update($name, $options)` |

```php
$client->host()->updateBuilder('ns1.example.com.ua')
    ->addAddresses('192.0.2.10', '2001:db8::10')
    ->remAddress('192.0.2.9')
    ->send();
```

IPv4 и IPv6 различаются по самому литералу, поэтому `v4` и `v6` проставляются верно без ваших
указаний, где что.

**Шага переименования нет.** Реестр не реализует `host:chg`, поэтому переименование этот билдер
выразить не может — три команды, которые делают эту работу вместо него, описаны в
[Хостах](hosts.md#переименования-не-существует).

Добавить и удалить один и тот же адрес в одной команде — противоречие, которое реестр разрешает так,
как сочтёт нужным. Отправляйте что-то одно.

---

## Что билдер не меняет

Билдер — это фасад над массивом опций, поэтому всё, чему подчиняется массив, действует и здесь:

- **Та же проверка.** `send()` вызывает обычный метод, и тот проверяет свои опции ровно так же, как
  сделал бы это для написанного руками массива. Билдер не может выдать неизвестный ключ, но может
  выдать сочетание, которое команда отвергнет.
- **Те же коды ответа.** `2302`, `2104`, `1001` означают то, что означают; см. [Ошибки](errors.md).
- **То же поведение `throwOnFailure`.** С выключенными исключениями `send()` возвращает отказ как
  `Response`, а не выбрасывает его.
- **То же обращение с секретами.** `authInfo()` задаёт действующие учётные данные. В собственном
  журнале библиотеки они маскируются, но `toOptions()` — это ваш массив, и если вы пишете его в
  журнал или кладёте в очередь, пароль едет в нём открытым текстом. Маскируйте его сами, прежде чем
  он попадёт в журнал.

## Какие шаги выбрасывают исключение до отправки

Все они — `ValidationException`, и ни в одном из случаев кадр не был собран:

| Шаг | Когда выбрасывает |
|---|---|
| любой `contact(…)` / `addContact(…)` / `remContact(…)` | роль пуста или состоит из пробелов |
| `nameserverWithGlue(…)` | имя сервера имён пусто |
| `dsRecord(…)`, `remDsRecord(…)`, `dsRecordWithKey(…)` | дайджест пуст |
| `keyRecord(…)`, `addKeyRecord(…)`, `remKeyRecord(…)`, `dsRecordWithKey(…)` | открытый ключ пуст |
| `removeAllDnssec()` после `remDsRecord()`/`remKeyRecord()` или любой из них после него | эти два взаимоисключающие |
| `maxFee(…)` | сумма не является простым десятичным числом вида `100.00` |
| `publish(…)` / `withhold(…)` | поле не входит в число name, org, addr, voice, fax, email |
| `send()` | билдер уже был отправлен |

Неправильно оформленное согласование тарифа проверяется здесь, а не на проводе, потому что иначе оно
получит голый `2001`, который не называет ни одного поля, — и придёт уже после того, как команда была
предпринята.

---

## Когда массив — более подходящий инструмент

Билдеры и массивы — это одно и то же, поэтому пользуйтесь тем, что подходит:

- Собираете из файла конфигурации, строки базы данных или полезной нагрузки очереди, которая и так
  является массивом: передавайте её прямо в `create()`/`update()`. Превращать её в цепочку вызовов
  лишь затем, чтобы билдер превратил её обратно в массив, — пустая работа.
- Пишете команду прямо в коде, особенно update: берите билдер. `->remStatus('clientHold')` называет
  блок, в который попадёт изменение, в том месте, где ошибиться нельзя.

`toOptions()` — мост между этими двумя путями, и он работает в обе стороны: соберите текучим API,
сохраните массив, позже проиграйте его прямым вызовом.

---

См. также: [Домены](domains.md) · [Контакты](contacts.md) · [Хосты](hosts.md) ·
[Баланс и цены](balance.md) · [Ответы](responses.md) · [Ошибки](errors.md)

[← К содержанию руководства](README.md)
