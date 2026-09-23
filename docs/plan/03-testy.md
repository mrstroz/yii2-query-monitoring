# E2. Architektura i konwencje testów

**Cel:** uporządkować zestaw testów, zanim dojdą MongoDB i tryb konsolowy, żeby nowe testy pisały się w jednej, opisanej strukturze zamiast powielać obecny układ.

**Koniec etapu:** testy dzielą się na jednostkowe, integracyjne Yii, systemu plików i procesów; w `tests/Unit` nie ma testu sięgającego po prawdziwy system plików, powłokę ani osobny proces; konwencje nazewnictwa, providerów, helperów, komentarzy i struktury testu są spisane w [`tests/README.md`](../../tests/README.md) i zastosowane w całym zestawie; żaden istotny scenariusz nie zniknął; `composer test`, `composer stan` i `composer cs` przechodzą na PHP 8.1 i 8.4, a testy zależne od baz nadal działają na MySQL i PostgreSQL. Zachowanie kodu produkcyjnego nie zmienia się w żadnym zadaniu etapu.

**Zależności zewnętrzne:** brak.

## Zadania

- [x] (^) **YQM-19** Podział `tests/Integration` na `Yii/`, `Filesystem/` i `Process/` z osobnymi testsuite'ami
      ADR: [0008](../adr/0008-architektura-i-konwencje-testow.md) · Konwencje: [`tests/README.md`](../../tests/README.md)
      Gotowe, gdy: `phpunit.xml.dist` ma cztery testsuite'y `Unit`, `Yii`, `Filesystem`, `Process`, `docker compose run --rm --no-deps -e QM_MYSQL_DSN= -e QM_PGSQL_DSN= php vendor/bin/phpunit --testsuite Unit,Filesystem,Process` przechodzi bez pominiętego testu, a znormalizowana lista przypadków nie różni się od baseline zdjętego przed pierwszą zmianą: `vendor/bin/phpunit --list-tests | sed -n 's/^ - //p' | sed -E 's/^([A-Za-z0-9_\\]*\\)?//' | sort`, porównane przez `diff`, bez różnic. Liczba zestawów danych na metodę też bez zmian.
      `tests/Unit` zostaje w tym zadaniu nietknięte, przenosi je YQM-20. `tests/Integration/Filesystem/` jest po tym zadaniu pusty, więc trzyma go `.gitkeep`, który znika w YQM-20; bez niego `TestSuiteMapper` rzuca `TestDirectoryNotFoundException` w świeżym klonie. Do `Process/` idą dokładnie dwie klasy, `FileAdapterConcurrencyTest` i `ProcessGroupTest` — jedyne dziedziczące wprost z `TestCase`; pozostałe jedenaście stoi na `IntegrationTestCase` i wędruje z grupą `Yii/`. `FileAdapterProtectionTest` należy do `Yii/`, bo badanym kontraktem jest odpowiedź aplikacji, a nie blokada.

- [x] (^) **YQM-20** `FileAdapterTest` i jego fixture poza `tests/Unit`
      ADR: [0008](../adr/0008-architektura-i-konwencje-testow.md) · Zależy od: YQM-19
      Gotowe, gdy: `tests/Integration/Filesystem/.gitkeep` jest usunięty, `grep -rE 'proc_open|flock|/dev/full|open_basedir|ulimit|sys_get_temp_dir|mkdir\(' tests/Unit` kończy się kodem 1, wszystkie dotychczasowe przypadki `FileAdapterTest` są obecne na znormalizowanej liście z YQM-19 — dziesięć wierszy `FileAdapterTest::` — i przechodzą, w tym `testShortWriteIsTruncatedBackAndThrows` i `testPathOutsideOpenBasedirThrowsWithoutWarning`, jedyne dwa uruchamiające `fixtures/write-once.php`, a więc jedyne, które dowodzą poprawnego `use` w fixture; a `composer test` przechodzi.
      To jedyny plik w `tests/Unit` sięgający po środowisko: 14 trafień w `FileAdapterTest.php` i 2 w `fixtures/write-once.php`. Reszta `tests/Unit` nie ma powodu się ruszać. `fixtures/` idzie razem z testem, więc `const CHILD = __DIR__ . '/fixtures/…'` i obie głębokości `dirname()` zostają bez zmian. Poprawki wymaga natomiast `use` w `fixtures/write-once.php:11`: wskazuje `FileAdapterTest` po FQCN i woła `FileAdapterTest::batch()` w procesie potomnym. Po przeniesieniu klasy ten `use` pokazuje na nieistniejącą klasę, a ani `composer stan`, ani `composer cs` tego nie zgłoszą, bo składniowo jest poprawny. Błąd wyszedłby dopiero przy uruchomieniu testu i to w dziecku — jako nietypowy kod wyjścia albo pusty JSON w asercji, nie jako czytelny komunikat. Dowodem jest więc wykonanie tych przypadków, które uruchamiają dziecko (`ulimit -f`, `open_basedir`), a nie zielone `stan`.

- [ ] (=) **YQM-21** Jednolita struktura klas i metod testowych
      Konwencje: [`tests/README.md`](../../tests/README.md) · Zależy od: YQM-20
      Gotowe, gdy: `ConnectionListTest::mastersOnly()` i `ProtectionTest::faults()` stoją po ostatniej metodzie testowej swojej klasy, a trzy greppy strażnicze nadal kończą się kodem 1: klasa testowa bez `final` poza `IntegrationTestCase` i `LoggedTestCase`, metoda `public function test…` bez `: void`, `grep -rn --include='*.php' '\$this->assert' tests` po odfiltrowaniu ośmiu własnych helperów asercyjnych.
      Trzy greppy są zielone już dziś i mają takie zostać — to strażnik regresji, nie zmiana: wszystkie klasy testowe są `final`, każda metoda testowa ma `: void`, a jedyne `$this->assert…` w zestawie to osiem własnych helperów (`assertFinished`, `assertNoErrors`, `assertNoWarning`, `assertOnePackageError`, `assertProcessOk`, `assertProfilingAndLoggingOff`, `assertSameAnswer`, `assertTight`). Realna praca tego zadania to dwie prywatne fabryki danych stojące dziś przed testami (`ConnectionListTest:31`, `ProtectionTest:22`) oraz układ testu pustymi liniami — ten drugi jest regułą z `tests/README.md` egzekwowaną w przeglądzie, nie warunkiem maszynowym. Data providery zostają nad pierwszym używającym ich testem, zgodnie z konwencją, więc nie są tu „helperem przed testami".

- [ ] (=) **YQM-22** Jedna konwencja data providerów w całym zestawie
      Konwencje: [`tests/README.md`](../../tests/README.md) · Zależy od: YQM-21
      Gotowe, gdy: `grep -rhn --include='*.php' '#\[DataProvider(' tests | grep -v 'provide[A-Za-z]*Cases'` kończy się kodem 1, każda metoda-provider ma sygnaturę `public static function provide…Cases(): iterable`, każdy zestaw danych ma nazwę, a `composer test` przechodzi.
      Czternaście nazw w czterech stylach; `databases()` jest w `IntegrationTestCase` i obsługuje około czterdziestu metod, więc idzie w tym samym commicie. Przemianowanie obejmuje też wywołania poza atrybutem — `FinalizationTest.php:30` woła `self::databases()` wprost. W zakresie są też pętle `foreach (['mysql', 'pgsql'] as $db)` obchodzące `databases()` — `FileAdapterProtectionTest:64`, `ConnectionListTest:109`, `FileAdapterComponentTest:45,74`, `QueryMonitorComponentTest:117`; grep z warunku ich nie łapie, a część z nich i tak traci drugi silnik w YQM-24. Nadanie nazw zestawom w `SqlNormalizerTest` (`unsupportedDbs`, `tooSmallLimits`) zmienia etykiety na liście przypadków; to jedyna dopuszczona różnica tego zadania i wpisuje się ją do tabeli z YQM-24.

- [ ] (=) **YQM-23** Asercja na kontrakt zamiast na natywny komunikat systemu
      Konwencje: [`tests/README.md`](../../tests/README.md) · Zależy od: YQM-20
      Gotowe, gdy: `grep -rn --include='*.php' 'fwrite(): Write of\|No space left on device' tests` kończy się kodem 1, asercja sprawdza operację i ścieżkę (`could not write to <path>: `), a każde z trzech powtórzeń wymienionych niżej jest zastąpione jednym helperem, bez ukrycia kroku scenariusza ([`tests/README.md`](../../tests/README.md), „Wspólny helper wprowadzamy dla zachowania, które naprawdę jest wspólne").
      Jedyny taki przypadek to `FileAdapterTest.php:115-116` — komunikat zależny od wersji PHP i locale, podczas gdy [spec 03 §2](../spec/03-adaptery-wyjsciowe.md#2-błąd-adaptera) wymaga rodzaju operacji i ścieżki. Progi czasowe (`MeasuredCommandTest:15`, `ConnectionListTest:26`, `ProcessGroupTest:57,72,84`) to kontrakt, nie kruchość, i to zadanie ich nie rusza. Format komunikatu `FileAdapterException` zostaje bez zmian, bo to kod produkcyjny. Trzy powtórzenia do scalenia: katalog tymczasowy `sys_get_temp_dir() . '/qm-…-' . bin2hex(random_bytes(6))` w `setUp()`/`tearDown()` czterech klas (`FileAdapterTest:38`, `FileAdapterProtectionTest:23`, `FileAdapterConcurrencyTest:30`, `ProcessGroupTest:51`); trzy asercje o zakończonym procesie powtórzone inline w `FileAdapterConcurrencyTest:53-55` wobec `ProcessGroupTest::assertFinished()`; parsowanie JSON Lines w `FileAdapterComponentTest::singleLine()` wobec pętli w `FileAdapterConcurrencyTest:78-84`.

- [ ] (=) **YQM-24** Parametryzacja bazą tylko tam, gdzie zachowanie idzie przez sterownik
      ADR: [0008](../adr/0008-architektura-i-konwencje-testow.md) · Zależy od: YQM-22
      Gotowe, gdy: tabela „Redukcja parametryzacji bazą" w „Uwagach" jest **przed redukcją** uzupełniona o każdą metodę i zestaw danych, który traci drugi silnik, wraz z powodem „nie przechodzi przez sterownik"; po zmianie zbiór nazw metod na znormalizowanej liście jest identyczny z baseline, a liczba zestawów danych na metodę jest identyczna wszędzie poza wierszami tej tabeli.
      Kandydaci sprawdzeni w kodzie: `FileAdapterComponentTest::testEachFileKeyOverridesOnlyItself` (provider `overrides`) i `::testBadFileSettingDisablesThePackageBeforeTheCommandSwap` (provider `badFileSettings`) — obie badają walidację i ustawienia klucza `file`, baza jest tylko nośnikiem połączenia. **Nie wolno redukować** `ReadmeExampleTest::testConfigurationExampleRunsInTheTestApplication`: mimo nazwy naprawdę tworzy schemat, wykonuje żądanie `order/index` i sprawdza wynikową paczkę, więc idzie przez sterownik. To samo dotyczy całego `TestApplicationTest` — wszystkie jego metody wołają `Schema::create()` albo żądanie z zapytaniami. Sam licznik testów redukcji nie rozstrzygnie, bo jest zamierzona; rozstrzyga tabela.

- [ ] (=) **YQM-25** Testy niezależne od kolejności i sprzątające po nieudanej asercji
      Konwencje: [`tests/README.md`](../../tests/README.md) · Zależy od: YQM-24
      Gotowe, gdy: `vendor/bin/phpunit --order-by=random --random-order-seed=1` i to samo z `--random-order-seed=2` kończą się kodem 0, a po przebiegu z celowo zepsutą asercją w teście plikowym w `sys_get_temp_dir()` nie zostaje katalog `qm-file-*` ani proces potomny `ProcessGroup`.
      Sprzątanie po nieudanej asercji jest sprawdzalne wyłącznie przez wymuszony błąd; bez tego kroku warunek byłby deklaracją.

- [ ] (=) **YQM-26** Odbiór etapu na skrajnych wersjach macierzy i obu bazach
      ADR: [0008](../adr/0008-architektura-i-konwencje-testow.md) · Zależy od: YQM-25
      Gotowe, gdy: `composer test`, `composer stan` i `composer cs` przechodzą na PHP 8.1 i 8.4 na obu bazach, uruchomione lokalnie przez `docker run` jak w YQM-11, a znormalizowana lista przypadków zgadza się z baseline z YQM-19 co do zbioru nazw metod i co do liczby zestawów poza wierszami tabeli z YQM-24.
      Workflow CI nie wymaga zmiany, bo `composer test` obejmuje wszystkie cztery testsuite'y. „Zielone CI na GitHubie" nie jest tu warunkiem, bo pierwszy push nie nastąpił i workflow pozostaje niepotwierdzony od E1.

## Czego reorganizacja nie robi

- Nie usuwa scenariusza dlatego, że jest trudny. Scenariusz, który znika, jest wymieniony z nazwą w tabeli z YQM-24 razem z powodem.
- Nie zastępuje testu integracyjnego mockiem, gdy badane zachowanie zależy od Yii, bazy, procesu, blokady albo systemu plików. Sonda ADR 0005 z ośmioma procesami zostaje sondą na procesach.
- Nie zmienia zachowania kodu produkcyjnego pod pretekstem testowalności. Zmiany w `phpunit.xml.dist` i w `composer.json` (autoload-dev PSR-4) są konfiguracją testów i należą do YQM-19; `src/` nie zmienia się w żadnym zadaniu etapu.
- Nie łączy całości w jeden commit. Osiem zadań to osiem commitów, w kolejności numerów.

## Uwagi

Wszystkie komendy w warunkach biegną przez `docker compose run --rm php …` — host nie ma PHP ani Composera.

Baseline znormalizowanej listy przypadków zdejmuje się przed pierwszą zmianą w YQM-19 i dołącza do commita tego zadania; kolejne zadania porównują się do niego, nie do poprzedniego kroku.

Katalogi `tests/app`, `tests/Integration/scenarios`, `tests/Integration/workers` i `tests/Integration/support` zostają na miejscu. Ich pliki są ładowane po ścieżce, nie przez autoload: `ScenarioController.php:21` składa `dirname(__DIR__, 2) . "/Integration/scenarios/{$name}.php"`, a `AppRunner` i `ProcessGroup` budują ścieżki ręcznie, więc PHPStan nie wykryje zepsutej ścieżki. Przeniesienie któregokolwiek z tych katalogów byłoby osobnym zadaniem z warunkiem o istnieniu wszystkich składanych ścieżek.

### Redukcja parametryzacji bazą (YQM-24)

Tabelę uzupełnia się przed zmianą, nie po niej. Dwa pierwsze wiersze są sprawdzone w kodzie; reszta dopisuje się w trakcie zadania.

| Metoda | Zestawy tracące silnik | Powód |
|---|---|---|
| `FileAdapterComponentTest::testEachFileKeyOverridesOnlyItself` | provider `overrides`, warianty `pgsql` | ustawienia klucza `file`, baza jest tylko nośnikiem połączenia |
| `FileAdapterComponentTest::testBadFileSettingDisablesThePackageBeforeTheCommandSwap` | provider `badFileSettings`, warianty `pgsql` | walidacja konfiguracji przed podmianą `Command`, nic nie idzie przez sterownik |
| … | … | … |

Poza tabelą nic nie traci drugiego silnika. `ReadmeExampleTest::testConfigurationExampleRunsInTheTestApplication` i wszystkie metody `TestApplicationTest` zostają na obu bazach, bo tworzą schemat i wykonują żądania z zapytaniami.

Ścieżki, które trzeba poprawić przy przenoszeniu, i te, których nie wolno „poprawić":

| Miejsce | Co się dzieje |
|---|---|
| `ReadmeExampleTest.php:103` | `__DIR__ . '/../../README.md'` potrzebuje trzech poziomów po przejściu do `Integration/Yii` |
| `FileAdapterConcurrencyTest.php:42`, `ProcessGroupTest.php:16` | `__DIR__ . '/workers/…'` musi być `__DIR__ . '/../workers/…'`, bo `workers/` zostaje w `tests/Integration/`. Nie `dirname(__DIR__)`: w `ProcessGroupTest` to `private const`, a wyrażenie stałe nie przyjmuje wywołania funkcji („Constant expression contains invalid operations"). Jeden idiom w obu plikach, żeby nikt ich nie „ujednolicił" z powrotem do fatala |
| `FileAdapterTest.php:26`, `fixtures/write-once.php:11` | relacja test↔fixture i `use` po FQCN zmieniają się razem z namespace'em w YQM-20 |
| `fixtures/write-once.php:13`, `FileAdapterTest.php:161` | `dirname(__DIR__, 4)` i `dirname(__DIR__, 3)` zostają bez zmian: `tests/Unit/adapter/fixtures` i `tests/Integration/Filesystem/fixtures` leżą na tej samej głębokości |
| `LoggedTestCase.php:21`, `QueryBatchTest.php:18` | `__DIR__ . '/../..'` zostaje, dopóki plik nie zmienia głębokości |

Zamiast zliczania `dirname` lepiej jedna stała z katalogiem głównym repozytorium, naturalnie w `tests/bootstrap.php` (dziś `__DIR__ . '/../vendor/...'` w liniach 5–6).

`failOnSkipped="true"` obowiązuje dalej, więc testy w `Filesystem/` i `Process/` nie mogą polegać na `markTestSkipped`. Jedynym dopuszczonym pominięciem zostaje brak DSN w `IntegrationTestCase::requireDatabase()`.

Podział pracy w etapie: przenoszenie i przepisywanie testów należy do testera, a `phpunit.xml.dist`, `composer.json` i `.github/workflows/ci.yml` zmienia driver. Zadania YQM-19 i YQM-26 przecinają obie własności, więc wykonuje je się parami, nie równolegle.

Numeracja MongoDB startuje od YQM-27.
