# Jak pisze się tu testy

Reguły praktyczne. Dlaczego podział wygląda tak, a nie inaczej, i dlaczego stoimy na PHPUnit 10, jest w [ADR 0008](../docs/adr/0008-architektura-i-konwencje-testow.md). Kolejność wdrażania tych reguł w istniejącym zestawie jest w [`docs/plan/03-testy.md`](../docs/plan/03-testy.md).

## Gdzie położyć test

O katalogu decydują rzeczywiste zależności testu i kontrakt, który sprawdza — nie nazwa testowanej klasy. Test klasy `FileAdapter`, który naprawdę pisze na dysk, nie jest testem jednostkowym dlatego, że `FileAdapter` ma jedną odpowiedzialność.

| Katalog | Co tam trafia |
|---|---|
| `Unit/` | Bez bazy, bez osobnego procesu, bez aplikacji Yii. Bez zapisu plików, katalogów tymczasowych i blokad; odczyt fixture'u z repozytorium jest w porządku |
| `Integration/Yii/` | Prawdziwa aplikacja Yii, opcjonalnie MySQL albo PostgreSQL |
| `Integration/Filesystem/` | Prawdziwe pliki, katalogi, blokady, uprawnienia, rotacja |
| `Integration/Process/` | Procesy potomne, bariery, timeouty, sygnały, kody wyjścia |

Ta tabela i reguła niżej powtarzają punkty 6–8 z ADR 0008, żeby dało się ich użyć bez otwierania ADR-u. Zmiana któregoś z tych punktów jest zmianą w dwóch plikach.

Test sięgający po `/dev/full`, `flock`, `open_basedir`, `sh`, `ulimit`, `proc_open` albo osobny proces nie leży w `Unit/`. Odwrotna reguła też obowiązuje: proces potomny użyty wyłącznie po to, żeby ustawić coś nieodwracalnego (`ulimit -f`, `open_basedir`), albo jako model jednego żądania PHP-FPM, nie robi z testu testu procesów — liczy się to, co test sprawdza.

## Klasa i metoda

- Klasa testowa jest `final`. Wyjątkiem są klasy bazowe pisane świadomie jako bazowe, takie jak `IntegrationTestCase` i `LoggedTestCase`.
- Metoda testowa to `public function test…(): void` i nazywa obserwowalne zachowanie, nie wywoływaną metodę: `testBusyLockDropsTheBatch`, nie `testWrite2`.
- Ciało testu dzieli się pustymi liniami na przygotowanie, wykonanie i asercje. Nie piszemy komentarzy `Arrange`, `Act`, `Assert` — puste linie mówią to samo.
- Jeden test opisuje jedno zachowanie. Asercji może być kilka, jeśli wszystkie opisują to jedno zachowanie.
- Helpery, fabryki danych i metody techniczne idą na koniec klasy, pod testami.
- W `Integration/Yii/` klasa `Yii` jest przesłonięta segmentem namespace'u: pisz `\Yii::…` albo dodaj `use Yii;`. Bez tego `Yii::$app` szuka `…\tests\Integration\Yii\Yii` i wywraca się dopiero w czasie wykonania.

## Asercje

- Asercje PHPUnit wołamy przez `self::assert*`.
- Własne helpery asercyjne wołamy przez `$this`: `$this->assertFinished(…)`, `$this->assertNoErrors(…)`. To jedyne dopuszczone `$this->assert…` w kodzie testu.
- Komunikat asercji niesie kontekst diagnostyczny, nie powtórzenie nazwy metody: `'second execution reuses the statement'`, nie `'assertion failed'`.
- Nie opieramy testu na pełnym natywnym komunikacie systemu operacyjnego, gdy publiczny kontrakt wymaga tylko rodzaju operacji i ścieżki. `No space left on device` zależy od wersji PHP i locale; `could not write to <path>` jest tym, co obiecuje [spec 03 §2](../docs/spec/03-adaptery-wyjsciowe.md#2-błąd-adaptera).
- Próg czasowy jest dopuszczalny, gdy sprawdza kontrakt (timeout zabija proces, przygotowanie liczone raz), i ma nad sobą komentarz z marginesem oraz środowiskiem, w którym był mierzony.

## Data providery

- Provider jest `public static function provide…Cases(): iterable` — jedna konwencja nazw w całym zestawie.
- Provider podpina się atrybutem `#[DataProvider('provide…Cases')]`.
- Każdy zestaw danych ma nazwę: `yield 'pgsql identifier' => [...]`, nie `yield [...]`. Bez nazw lista przypadków mówi `#0`, a zmiana kolejności staje się niewidoczna.
- Provider deklaruje `@return iterable<string, array{…}>`. Przy tym typie PHPStan na poziomie 8 odrzuca `yield` bez nazwy, więc nazwane zestawy egzekwuje `composer stan`, a nie tylko przegląd.
- Provider stoi bezpośrednio nad pierwszym testem, który go używa, a gdy używa go kilka testów — nad pierwszym z nich.
- Rodzaj bazy parametryzujemy tylko wtedy, gdy zachowanie przechodzi przez sterownik albo przez implementację zależną od bazy, albo gdy świadomie potwierdzamy wsparcie obu silników. Scenariusz niezależny od bazy nie jest mnożony przez dwa silniki „na wszelki wypadek".
- Przypadki na MySQL i PostgreSQL idą przez wspólny mechanizm `IntegrationTestCase`, nie przez własną listę DSN w teście.

## Komentarze

Komentarz wyjaśnia powód, ograniczenie platformy albo nietypowy kontrakt. Nie przepisuje kodu.

```php
// Drugi flock w tym samym procesie zwraca false z wouldBlock — sprawdzone w obrazie 8.1.
```

## Izolacja i sprzątanie

- Test nie zależy od kolejności wykonania ani od stanu zostawionego przez inny test. Zestaw musi przechodzić przy `--order-by=random`.
- Test sprząta swoje pliki i procesy także wtedy, gdy asercja padła — czyli w `tearDown()` albo przez `try/finally`, nie w ostatniej linii metody testowej.
- Pliki testowe powstają w `sys_get_temp_dir()` kontenera, nie w katalogu `/app` montowanym z hosta.
- Wspólny helper wprowadzamy dla zachowania, które naprawdę jest wspólne. Helper ukrywający istotny krok scenariusza szkodzi bardziej niż powtórzenie trzech linii.

## Pominięte testy

`phpunit.xml.dist` ma `failOnSkipped="true"`, więc pominięty test czerwieni cały bieg — PHPUnit wypisuje wtedy „OK, but some tests were skipped!" i kończy się kodem 1. Jedyne dopuszczone pominięcie to brak skonfigurowanego DSN w `IntegrationTestCase::requireDatabase()`. Test w `Filesystem/` albo `Process/` nie może opierać się na `markTestSkipped`: albo umie działać w środowisku, w którym biegnie CI, albo nie powstaje.

## Testy wymagające środowiska

Test sięgający po urządzenie, limit systemowy albo uprawnienia dokumentuje u siebie, czego wymaga: Linux, proces bez uprawnień roota, dostępne `/dev/full`, `ulimit -f`. Jedno zdanie w komentarzu nad metodą wystarczy, ale musi być — bez niego czerwony wynik na innej maszynie wygląda jak błąd pakietu.

## Uruchamianie

```
docker compose run --rm php composer test                 # wszystko
docker compose run --rm php vendor/bin/phpunit --testsuite Unit
# Bez baz. --no-deps jest konieczne: bez niego depends_on podnosi MySQL i PostgreSQL mimo pustych DSN.
docker compose run --rm --no-deps -e QM_MYSQL_DSN= -e QM_PGSQL_DSN= php vendor/bin/phpunit --testsuite Unit,Filesystem,Process
```
