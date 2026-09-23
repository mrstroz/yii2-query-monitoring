# E2. Architektura i konwencje testów

**Cel:** uporządkować zestaw testów, zanim dojdą MongoDB i tryb konsolowy, żeby nowe testy pisały się w jednej, opisanej strukturze zamiast powielać obecny układ.

**Koniec etapu:** testy dzielą się na jednostkowe, integracyjne Yii, systemu plików i procesów; w `tests/Unit` nie ma testu sięgającego po prawdziwy system plików, powłokę ani osobny proces; konwencje nazewnictwa, providerów, helperów, komentarzy i struktury testu są spisane w [`tests/README.md`](../../tests/README.md) i zastosowane w całym zestawie; żaden istotny scenariusz nie zniknął; `composer test`, `composer stan` i `composer cs` przechodzą na PHP 8.1 i 8.4, a testy zależne od baz nadal działają na MySQL i PostgreSQL. Zachowanie kodu produkcyjnego nie zmienia się w żadnym zadaniu etapu.

**Zależności zewnętrzne:** brak.

## Zadania

- [x] (^) **YQM-19** Podział `tests/Integration` na `Yii/`, `Filesystem/` i `Process/` z osobnymi testsuite'ami
      ADR: [0008](../adr/0008-architektura-i-konwencje-testow.md) · Konwencje: [`tests/README.md`](../../tests/README.md)
      Gotowe, gdy: `phpunit.xml.dist` ma cztery testsuite'y `Unit`, `Yii`, `Filesystem`, `Process`, `docker compose run --rm --no-deps -e QM_MYSQL_DSN= -e QM_PGSQL_DSN= php vendor/bin/phpunit --testsuite Unit,Filesystem,Process` przechodzi bez pominiętego testu, a znormalizowana lista przypadków nie różni się od baseline zdjętego przed pierwszą zmianą: `vendor/bin/phpunit --list-tests | sed -n 's/^ - //p' | sed -E 's/^([A-Za-z0-9_\\]*\\)?//' | LC_ALL=C sort`, porównane przez `diff`, bez różnic. `LC_ALL=C` jest częścią komendy, nie ozdobą: bez niego kolejność zależy od locale, a obraz kontenera ma wyłącznie `C`, `C.utf8` i `POSIX`, więc `LC_ALL=pl_PL.UTF-8` cicho spada tam do `C` i daje inny wynik niż na hoście. Obie strony porównania sortuje się tym samym poleceniem w chwili `diff`. Liczba zestawów danych na metodę też bez zmian.
      `tests/Unit` zostaje w tym zadaniu nietknięte, przenosi je YQM-20. `tests/Integration/Filesystem/` jest po tym zadaniu pusty, więc trzyma go `.gitkeep`, który znika w YQM-20; bez niego `TestSuiteMapper` rzuca `TestDirectoryNotFoundException` w świeżym klonie. Do `Process/` idą dokładnie dwie klasy, `FileAdapterConcurrencyTest` i `ProcessGroupTest` — jedyne dziedziczące wprost z `TestCase`; pozostałe jedenaście stoi na `IntegrationTestCase` i wędruje z grupą `Yii/`. `FileAdapterProtectionTest` należy do `Yii/`, bo badanym kontraktem jest odpowiedź aplikacji, a nie blokada.

- [x] (^) **YQM-20** `FileAdapterTest` i jego fixture poza `tests/Unit`
      ADR: [0008](../adr/0008-architektura-i-konwencje-testow.md) · Zależy od: YQM-19
      Gotowe, gdy: `tests/Integration/Filesystem/.gitkeep` jest usunięty, `grep -rE 'proc_open|flock|/dev/full|open_basedir|ulimit|sys_get_temp_dir|mkdir\(' tests/Unit` kończy się kodem 1, wszystkie dotychczasowe przypadki `FileAdapterTest` są obecne na znormalizowanej liście z YQM-19 — dziesięć wierszy `FileAdapterTest::` — i przechodzą, w tym `testShortWriteIsTruncatedBackAndThrows` i `testPathOutsideOpenBasedirThrowsWithoutWarning`, jedyne dwa uruchamiające `fixtures/write-once.php`, a więc jedyne, które dowodzą poprawnego `use` w fixture; a `composer test` przechodzi.
      Kontekst: `FileAdapterTest` to jedyny plik w `tests/Unit` sięgający po środowisko (14 trafień w nim, 2 w `fixtures/write-once.php`), więc reszta katalogu się nie rusza. `fixtures/` idzie razem z testem, a `use` w `fixtures/write-once.php:11` wskazuje `FileAdapterTest` po FQCN i po przeniesieniu klasy pokazywałby na nieistniejącą — ani `stan`, ani `cs` tego nie zgłoszą, bo składniowo jest poprawny. Dowodem jest więc wykonanie dwóch przypadków uruchamiających proces potomny (`ulimit -f`, `open_basedir`), a nie zielony `stan`.

- [x] (=) **YQM-21** Jednolita struktura klas i metod testowych
      Konwencje: [`tests/README.md`](../../tests/README.md) · Zależy od: YQM-20
      Gotowe, gdy: `ConnectionListTest::mastersOnly()` i `ProtectionTest::faults()` stoją po ostatniej metodzie testowej swojej klasy, a trzy greppy strażnicze nadal kończą się kodem 1: klasa testowa bez `final` poza `IntegrationTestCase` i `LoggedTestCase`, metoda `public function test…` bez `: void`, `grep -rn --include='*.php' '\$this->assert' tests` po odfiltrowaniu ośmiu własnych helperów asercyjnych.
      Trzy greppy są zielone już dziś i mają takie zostać — to strażnik regresji, nie zmiana: wszystkie klasy testowe są `final`, każda metoda testowa ma `: void`, a jedyne `$this->assert…` w zestawie to osiem własnych helperów (`assertFinished`, `assertNoErrors`, `assertNoWarning`, `assertOnePackageError`, `assertProcessOk`, `assertProfilingAndLoggingOff`, `assertSameAnswer`, `assertTight`). Realna praca tego zadania to dwie prywatne fabryki danych stojące przed testami (`ConnectionListTest:31` → 122, `ProtectionTest:22` → 102) oraz układ testu pustymi liniami — ten drugi jest regułą z `tests/README.md` egzekwowaną w przeglądzie, nie warunkiem maszynowym. Data providery zostają nad pierwszym używającym ich testem, zgodnie z konwencją, więc nie są tu „helperem przed testami".

- [x] (=) **YQM-22** Jedna konwencja data providerów w całym zestawie
      Konwencje: [`tests/README.md`](../../tests/README.md) · Zależy od: YQM-21
      Gotowe, gdy: `grep -rhn --include='*.php' '#\[DataProvider(' tests | grep -v 'provide[A-Za-z]*Cases'` kończy się kodem 1, każda metoda-provider ma sygnaturę `public static function provide…Cases(): iterable`, każdy zestaw danych ma nazwę, a `composer test` przechodzi.
      Czternaście nazw w czterech stylach; `databases()` jest w `IntegrationTestCase` i obsługuje około czterdziestu metod, więc idzie w tym samym commicie. Przemianowanie obejmuje też wywołania poza atrybutem — `FinalizationTest.php:30` woła `self::databases()` wprost. W zakresie są też pętle `foreach (['mysql', 'pgsql'] as $db)` obchodzące `databases()` — `FileAdapterProtectionTest:64`, `ConnectionListTest:109`, `FileAdapterComponentTest:45,74`, `QueryMonitorComponentTest:117`; grep z warunku ich nie łapie, a część z nich i tak traci drugi silnik w YQM-24. Nadanie nazw zestawom w `SqlNormalizerTest` zmienia etykiety na liście przypadków; to jedyna dopuszczona różnica tego zadania, wypisana w „Uwagach" pod tabelą YQM-24. Nazwanie zestawu robi z klucza `string`, więc docblok providera musi mówić `iterable<string, …>` — bez tego PHPStan na poziomie 8 daje `Generator expects key type int, string given`, po jednym błędzie na zestaw.

- [x] (=) **YQM-23** Asercja na kontrakt zamiast na natywny komunikat systemu
      Konwencje: [`tests/README.md`](../../tests/README.md) · Zależy od: YQM-20
      Gotowe, gdy: `grep -rn --include='*.php' 'fwrite(): Write of\|No space left on device' tests` kończy się kodem 1, asercja sprawdza operację i ścieżkę (`could not write to <path>: `), a każde z trzech powtórzeń wymienionych niżej jest zastąpione jednym helperem, bez ukrycia kroku scenariusza ([`tests/README.md`](../../tests/README.md), „Wspólny helper wprowadzamy dla zachowania, które naprawdę jest wspólne").
      Kontekst: jedyna taka asercja to `Integration/Filesystem/FileAdapterTest.php:117` — komunikat zależny od wersji PHP i locale tam, gdzie [spec 03 §2](../spec/03-adaptery-wyjsciowe.md#2-błąd-adaptera) wymaga rodzaju operacji i ścieżki. Progi czasowe (`MeasuredCommandTest:15`, `ConnectionListTest:26`, `ProcessGroupTest`) są kontraktem i to zadanie ich nie rusza; format `FileAdapterException` też zostaje, bo to kod produkcyjny.
      Trzy powtórzenia idą do `tests/Integration/support/`: katalog tymczasowy z `setUp()`/`tearDown()` trzech klas (trait `TemporaryDirectory`), asercja o zakończonym procesie (trait `ProcessAssertions`) i parsowanie JSON Lines (`JsonLines::decode()`). Tworzenie katalogu zostaje jawne w każdej klasie, bo przy testach systemu plików moment jego powstania jest krokiem scenariusza. Powody techniczne poszczególnych decyzji stoją w docblokach tych plików, nie tutaj.

- [x] (=) **YQM-24** Parametryzacja bazą tylko tam, gdzie zachowanie idzie przez sterownik
      ADR: [0008](../adr/0008-architektura-i-konwencje-testow.md) · Zależy od: YQM-22
      Gotowe, gdy: tabela „Redukcja parametryzacji bazą" w „Uwagach" jest **przed redukcją** uzupełniona o każdą metodę i zestaw danych, który traci drugi silnik, wraz z numerem kroku sprawdzianu z [`tests/README.md`](../../tests/README.md), pod którym decyzja zapadła, oraz z linią albo komendą, którą da się ten powód obalić; po zmianie zbiór nazw metod na znormalizowanej liście jest identyczny z baseline, a liczba zestawów danych na metodę jest identyczna wszędzie poza wierszami tej tabeli.
      Tabela pochodzi z audytu wszystkich 58 metod parametryzowanych bazą, przeprowadzonego pod sprawdzianem z [`tests/README.md`](../../tests/README.md); metoda audytu jest w „Uwagach", żeby YQM-26 mógł go powtórzyć. Każdy wiersz ma powód sprawdzalny komendą albo linią — powód, który brzmi wiarygodnie, ale nie da się go uruchomić, mówi o intencji, nie o kodzie. Sam licznik testów redukcji nie rozstrzygnie, bo jest zamierzona; rozstrzyga tabela i cztery liczby niżej.

- [x] (=) **YQM-25** Testy niezależne od kolejności i sprzątające po nieudanej asercji
      Konwencje: [`tests/README.md`](../../tests/README.md) · Zależy od: YQM-24
      Gotowe, gdy: `vendor/bin/phpunit --order-by=random --random-order-seed=1` i to samo z `--random-order-seed=2` kończą się kodem 0, a po przebiegu z celowo wymuszonym błędem — zepsuta asercja w klasie plikowej, zmieniony deadline w `ProcessGroupTest` — w `sys_get_temp_dir()` nie przybywa **nic pasującego do `qm-*`** ani proces potomny `ProcessGroup`. Dowód porównuje zawartość `sys_get_temp_dir()` przed biegiem i po nim, **w jednym kontenerze** (`--rm` kasuje `/tmp` między biegami, więc listingi z dwóch kontenerów dają zawsze pusty diff) — „puste" ma znaczyć „nic nie przybyło", a nie „nic tam nie widzę" — i mówi, **jak** wymuszono błąd w każdej klasie oraz co po nim zostało.
      Sprzątanie po nieudanej asercji jest sprawdzalne wyłącznie przez wymuszony błąd; bez tego kroku warunek byłby deklaracją. Wzorzec jest `qm-*`, a nie lista przedrostków, bo lista byłaby węższa od komendy, którą i tak się uruchamia. W pierwszej kolejności chodzi o cztery przedrostki testowe, które YQM-23 zebrał w jedno źródło (trait `TemporaryDirectory`): `qm-file-`, `qm-protection-`, `qm-concurrency-`, `qm-group-`. Ale `AppRunner` tworzy w `sys_get_temp_dir()` trzy kolejne na **każde** uruchomienie aplikacji, czyli setki razy w biegu (`tests/app/AppRunner.php:37-39`: `qm-capture-` wraz z `.calls`, `qm-log-`, katalog `qm-runtime-`), i sprząta je w `finally` (`:60-63`). Dziś nie ma tam wycieku; `qm-*` jest po to, żeby warunek złapał go, gdyby ten `finally` kiedyś przestał działać.
      Ciężar warunku leży w trzech klasach z katalogiem tymczasowym (`FileAdapterTest`, `FileAdapterProtectionTest`, `FileAdapterConcurrencyTest`): `tearDown()` już tam jest, a dowód ma pokazać, że działa **także po czerwonej asercji** — czyli że katalog znika, a nie że test przechodzi. `ProcessGroupTest` był osobnym przypadkiem: brak `tearDown()` był w nim poprawny, dopóki test nie tworzył niczego sam — marker `qm-group-*` powstaje wyłącznie w procesie, który przeżył zabicie (`workers/process-probe.php`, gałąź `sleep`, `touch()` po `QM_SLEEP`), a zabijanie dzieje się w `ProcessGroup::run()` przed asercjami. Plik `qm-group-*` po wymuszonym błędzie oznacza więc, że kill zawiódł — to osobne znalezisko, nie brak sprzątania, i wtedy warunek ma je pokazać, a nie zostać zawężony tak, żeby przeszedł. Odwrotność jest równie ważna i wygląda tak samo (pusty `ls`): brak markera po czerwonej asercji w tej klasie nie dowodzi, że sprząta — dowodzi, że nie miała czego sprzątać. Dowód nazywa, którą z tych dwóch rzeczy zmierzył.

Ten pomiar ma własny warunek czasowy, bez którego jest deklaracją. Marker powstaje dopiero po `QM_SLEEP` (1,5 s) od startu procesu, a `ProcessGroup::run()` wraca po deadline 0,5 s; przy wymuszonym błędzie na `ProcessGroupTest:60` (`assertLessThan(1.5, $elapsed)`) test kończy się przed `usleep(1_500_000)` w `:66`, a bieg jednego testu przez `--filter` kończy się, zanim ocalały proces zdążyłby dotknąć plik. Listing „po" wzięty od razu pokaże więc pusty `/tmp` także wtedy, gdy kill zawiódł — czyli dokładnie w sytuacji, którą warunek ma wykryć. Dlatego listing „po" i `pgrep -f process-probe` wykonuje się **nie wcześniej niż 1,5 s po zakończeniu biegu**, albo wymuszony błąd stoi **po** `usleep()`.

Drugi wymuszony błąd **nie jest złamaną asercją** i warunek musi to nazywać, bo inaczej ktoś zepsuje asercję, dostanie pusty listing i uzna, że klasa sprząta. Marker powstaje wyłącznie wtedy, gdy proces przeżyje zabicie, więc jedyny sposób, żeby w ogóle powstał, to **zmiana deadline'u** `ProcessGroup::run()` na wartość większą niż `QM_SLEEP` (zmierzone: `0.5` → `3.0` daje bieg 1,518 s i trzy markery). Wtedy czerwona jest asercja `assertLessThan(1.5, $elapsed)`, ale czerwoną ją czyni zmieniony deadline, a nie zepsuta asercja.

Dlatego wymuszone błędy są **dwa**: jeden w klasie z katalogiem tymczasowym, gdzie `tearDown()` istnieje i ma zadziałać mimo czerwonej asercji, i jeden w `ProcessGroupTest`, czyli w jedynym **wtedy** miejscu bez sprzątania. Pomiar pokazał, że warunek „nie tworzy niczego sam" nie był spełniony — markery zostawały — więc zadanie dokłada tej klasie `tearDown()`, a traitowi drugi tryb sprzątania. Złamanie asercji tylko w pierwszej z nich dowodzi wyłącznie tego, że PHPUnit woła `tearDown()` po nieudanej asercji — co wiadomo bez tego testu.

- [x] (=) **YQM-26** Odbiór etapu na skrajnych wersjach macierzy i obu bazach
      ADR: [0008](../adr/0008-architektura-i-konwencje-testow.md) · Zależy od: YQM-25
      Gotowe, gdy: `composer test`, `composer stan` i `composer cs` przechodzą na PHP 8.1 i 8.4 na obu bazach, uruchomione lokalnie przez Docker: obraz dla drugiej wersji budowany `PHP_VERSION=8.4 docker compose build php`, biegi przez `docker compose run --rm php composer …`, a wersja potwierdzona linią `Runtime:` w wyjściu PHPUnit, nie samą zmienną; znormalizowana lista przypadków (sortowana w `LC_ALL=C`, jak w YQM-19) zgadza się z baseline co do zbioru nazw metod i co do liczby zestawów poza wierszami tabeli z YQM-24; `grep -rl ANY_DB tests/Integration/Yii --include='*Test.php'` daje dokładnie sześć klas (`ConnectionListTest`, `FileAdapterComponentTest`, `FinalizationTest`, `ProtectionTest`, `QueryMonitorComponentTest`, `TestApplicationTest`), a każde trafienie z `grep -rn` tym samym wzorcem leży w ciele jednej z dziewięciu metod tabeli z YQM-24 i każda z tych metod ma co najmniej jedno — dziewięć metod w sześciu klasach. Liczy się metody, nie wiersze: dwie metody zachowują providera z innym wymiarem, więc podstawiają stałą w każdym z trzech wywołań, a `--include='*Test.php'` wycina plik z definicją stałej (`IntegrationTestCase.php`). Warunek na liczbę wierszy wiązałby odbiór ze stylem zapisu, nie z faktem o kodzie; a audyt z „Uwag" powtórzony tym samym filtrem po **pozostałych** metodach parametryzowanych bazą nie daje żadnej kandydatki bez nazwanego powodu — pierwsza połowa sprawdza zbiór, druga to, że nic nie zostało poza tabelą. Samo „ten sam zbiór dziewięciu wierszy" jest po YQM-24 niewykonalne: siedem metod traci providera baz w całości, a `provideBadFileSettingCases` i `provideBadConfigurationCases` przestają iterować po `provideDatabaseCases`, więc powtórzony audyt zobaczy 49 metod i zero kandydatek; każdy powód w tabeli ma komendę albo linię, którą da się uruchomić — powód bez nich mówi o intencji, nie o kodzie; `tests/baseline-yqm19.txt` i `tests/baseline-yqm19-sets.txt` są usunięte — jako **ostatni** krok zadania, po porównaniu i audycie, a nie przed nimi.
      Bieg odbiorczy idzie na zamrożonym drzewie: `git status --short` i suma treści śledzonych plików (`git ls-files -z | xargs -0 sha256sum | sha256sum`) przed pierwszym biegiem i po ostatnim, dosłownie w dowodzie, plus nazwa snapshota, z którego pochodzi kod. Jedyną dopuszczoną różnicą między tymi pomiarami jest **usunięcie plików baseline**, czyli ostatni krok samego zadania — bo zadanie te pliki usuwa, więc obie sumy z założenia nie mogą być równe. Różnicę sprawdza `git diff HEAD --stat`, które ma pokazać dokładnie te dwa pliki i nic ponadto (`2 files changed, 492 deletions(-)`); każda inna oznacza, że drzewo zmieniło się w trakcie biegów i pomiary pochodzą z dwóch różnych wydań. Sama suma jest zapisem stanu, `git diff HEAD --stat` mówi **co** się zmieniło — dlatego są obie. **Nie** `git rev-parse HEAD^{tree}`: hasz drzewa commita nie zmienia się od zmian w drzewie roboczym, więc nie wykryłby ani zmiany wprowadzonej i cofniętej między biegami, ani usunięcia plików baseline — sprawdzone.
      Odhaczając zadanie, czyta się jego kontekst jeszcze raz jako opis stanu **po** wykonaniu, i tak samo konteksty zadań sąsiednich oraz każdy inny plik mówiący o stanie (`CLAUDE.md`, `roadmap.md`). Pytanie brzmi „które zdania właśnie unieważniłem", a nie „czy mój kontekst jest nadal prawdziwy".
      Workflow CI nie wymaga zmiany, bo `composer test` obejmuje wszystkie cztery testsuite'y. „Zielone CI na GitHubie" nie jest tu warunkiem, bo pierwszy push nie nastąpił i workflow pozostaje niepotwierdzony od E1.

## Czego reorganizacja nie robi

- Nie usuwa scenariusza dlatego, że jest trudny. Scenariusz, który znika, jest wymieniony z nazwą w tabeli z YQM-24 razem z powodem.
- Nie zastępuje testu integracyjnego mockiem, gdy badane zachowanie zależy od Yii, bazy, procesu, blokady albo systemu plików. Sonda ADR 0005 z ośmioma procesami zostaje sondą na procesach.
- Nie zmienia zachowania kodu produkcyjnego pod pretekstem testowalności. Zmiany w `phpunit.xml.dist` i w `composer.json` (autoload-dev PSR-4) są konfiguracją testów i należą do YQM-19; `src/` nie zmienia się w żadnym zadaniu etapu.
- Nie łączy całości w jeden commit. Osiem zadań to osiem commitów, w kolejności numerów.

## Uwagi

Wszystkie komendy w warunkach biegną przez `docker compose run --rm php …` — host nie ma PHP ani Composera.

Baseline znormalizowanej listy przypadków zdjęto przed pierwszą zmianą w YQM-19 i dołączono do commita tego zadania; kolejne zadania porównywały się do niego, nie do poprzedniego kroku. YQM-26 usuwa oba pliki, bo po odbiorze etapu nie mają już do czego służyć; czyta się je z historii (`git show 8710462:tests/baseline-yqm19.txt`).

Katalogi `tests/app`, `tests/Integration/scenarios`, `tests/Integration/workers` i `tests/Integration/support` zostają na miejscu. Ich pliki są ładowane po ścieżce, nie przez autoload: `ScenarioController.php:21` składa `dirname(__DIR__, 2) . "/Integration/scenarios/{$name}.php"`, a `AppRunner` i `ProcessGroup` budują ścieżki ręcznie, więc PHPStan nie wykryje zepsutej ścieżki. Przeniesienie któregokolwiek z tych katalogów byłoby osobnym zadaniem z warunkiem o istnieniu wszystkich składanych ścieżek.

### Redukcja parametryzacji bazą (YQM-24)

Tabelę uzupełnia się przed zmianą, nie po niej; każdy wiersz jest sprawdzony w kodzie, zanim zacznie się redukcja.

| Metoda | Zestawy przed | Po | Powód (krok sprawdzianu) |
|---|---|---|---|
| `ConnectionListTest::testConnectionWithoutDsnIsSkippedWithoutConnecting` | 2 | 1 | `:32-42` — `probe()` bez `QM_PROBE_QUERY`; czyta brak otwarcia, czas biegu i błąd z **bramy instalacji**, nie z toru zapytania. Dla `dbMasters` instalacja kończy się na `QueryMonitor.php:208` (własne `dsn` jest `null`), więc `getDriverName()` nie jest nawet wołane (krok 3) |
| `ConnectionListTest::testListedConnectionIsNotOpenedByBootstrap` | 2 | 1 | `:56-60` — to samo bez `mastersOnly()`; mierzy brak otwarcia połączenia (krok 3) |
| `FileAdapterComponentTest::testBadFileSettingDisablesThePackageBeforeTheCommandSwap` | 12 | 6 | `:89-102` — pakiet odrzucony przy walidacji klucza `file`; `:101-102` sprawdza wprost, że `Command` nie został podstawiony, więc trzy zapytania scenariusza `per-connection` idą przez `yii\db\Command` (krok 3) |
| `FinalizationTest::testRequestWithoutQueriesSendsNothing` | 2 | 1 | `:69-77` — scenariusz `no-queries`; przez sterownik nie przechodzi nic (krok 3) |
| `ProtectionTest::testDisabledPackageLeavesNoTrace` | 2 | 1 | `:87-93` — `['enabled' => false]`, brak podmiany, `adapterCalls` puste (krok 3) |
| `QueryMonitorComponentTest::testDisabledChangesNothingAndSendsNothing` | 2 | 1 | `:91-100` — `enabled: false`; asercja `:94` czyta `yii\db\Command`, czyli wartość **domyślną**, identyczną dla obu silników (krok 3) |
| `QueryMonitorComponentTest::testRequestWithoutQueriesSendsNothing` | 2 | 1 | `:103-110` — scenariusz `no-queries` (krok 3) |
| `QueryMonitorComponentTest::testBadConfigurationDisablesPackageWithOneError` | 4 | 2 | `:127-138` — zła konfiguracja komponentu; `:138` czyta `yii\db\Command`, czyli brak podmiany (krok 3) |
| `TestApplicationTest::testApplicationRunsInAnotherProcess` | 2 | 1 | `:31-36` — scenariusz `process` zwraca `pid` i `sapi`; nie wykonuje zapytań (krok 3) |
| **Razem** | **30** | **15** | ubytek **15 zestawów** |

Liczby zestawów pochodziły z `tests/baseline-yqm19-sets.txt`, nie z oględzin kodu; plik usunięto w YQM-26, a historia go trzyma: `git show 8710462:tests/baseline-yqm19-sets.txt`.

Redukcja usuwa provider tam, gdzie baza była jedynym wymiarem, i podstawia `IntegrationTestCase::ANY_DB`; stała nazywa decyzję w miejscu użycia zamiast zostawiać providera z jednym elementem. Na liście przypadków daje to trzy rodzaje zmian, które przy odbiorze trzeba rozróżnić: wiersze **usunięte** (warianty `pgsql`), wiersze ze **zmienioną etykietą** (`"mysql: unknown key"` → `"unknown key"` tam, gdzie provider miał więcej wymiarów niż baza) i wiersze **bez etykiety** (metody, w których provider zniknął w całości).

Metoda audytu, żeby YQM-26 mógł go powtórzyć. Filtr **odsiewa** metody, które na pewno przeszły przez sterownik, i zostawia resztę do ręcznej lektury; odsiewają wyłącznie markery odczytu wyjścia pakietu: `entriesWith`, `singleBatch`, `->queries`, `batches[0]`, `->batches[`, `singleLine`. Trzy zasady, każda kupiona błędem:

- **Filtr stosuje się do ciała metody, nigdy do pliku.** `FinalizationTest.php` ma `singleBatch` i `entriesWith` w liniach 44–65, w zupełnie innych metodach niż kandydatka z `:69`; filtr po pliku przepuściłby ją po cichu.
- **Marker nieobecności — `assertSame([], $result->batches)`, `adapterCalls`, `runtimeFiles` — jest znakiem kandydatki, nie powodem odsiania.** Wpisany do sita wyrzuca z ręcznej listy sześć z ośmiu kandydatek.
- **`scenarioOutput` do filtru nie należy**: czyta wynik scenariusza, a nie wyjście pakietu. Z nim ręczna lista schodzi z 18 do 9 i znikają z niej cztery wiersze tabeli.

Wynik: 58 metod parametryzowanych bazą, 40 odsianych, **18 do przeczytania ręcznie**, z nich 9 do tabeli. Liczby policzone niezależnie w dwóch przebiegach.

Po redukcji tych metod jest 49 i ta liczba jest **sygnałem, nie warunkiem** — przy odbiorze YQM-26 zadziałała dokładnie tak: skrypt audytu szukał atrybutu `#[DataProvider]` do dwunastu wierszy wstecz, więc gdy siedem metod straciło atrybut w YQM-24, przypisywał im atrybut metody poprzedniej i naliczył 54 z pięcioma nieistniejącymi kandydatkami. Złapała to niezgodność z 49, a nie przegląd kodu. Dlatego takie wartości zostają w planie także dla E3, choć nie są warunkami.

Rozgałęzienia po bazie w `src/` — pełna lista wejść w krok 2 sprawdzianu (`grep -rn "Dialect\b\|driverName" src/` poza docblokami nie daje innych):

| Miejsce | Co rozgałęzia |
|---|---|
| `QueryMonitor.php:211-212` | `getDriverName()` + `Dialect::tryFrom()`, a `:224` zapisuje `commandMap[$driver]` — brama instalacji |
| `sql/SqlNormalizer.php:43,60,196,294` (plus predykaty `Dialect`) | reguły normalizacji per dialekt |
| `sql/Recorder.php:22,40-45` | `driverName` wchodzi do normalizatora i do pola `db` wpisu |

Dwa ostatnie leżą **za** podmianą `commandMap`, więc test, który do nich dociera, wykonał zapytanie przez nasz `Command` i zapada już krokiem 1. W całym audycie krok 2 zapadł **raz**, na bramie instalacji, i warto zobaczyć tę parę obok siebie, bo to ta sama mechanika z przeciwnym wynikiem:

- `QueryMonitorComponentTest::testListedConnectionsGetMeasuredCommand` (`:81-88`) **zostaje na obu silnikach**. `Connection::createCommand()` (`vendor/yiisoft/yii2/db/Connection.php:759-766`) bierze klasę spod `commandMap[getDriverName()]`, więc asercja czyta wartość spod klucza silnika: na pgsql dowodzi instalacji pod `pgsql`, na mysql pod `mysql`. Przy zepsutym `Dialect` dla jednego silnika to jedyny test, który powie „instalacja pod tym kluczem nie zaszła", a nie „brak wpisów".
- `::testDisabledChangesNothingAndSendsNothing` (`:94`) i `::testBadConfigurationDisablesPackageWithOneError` (`:138`) **są w tabeli**, choć ich asercje też idą przez `createCommand()`: czytają `yii\db\Command`, czyli wartość domyślną, identyczną dla obu silników i niezależną od klucza.

Pytanie kroku 2 brzmi więc: **czy klucz sterownika decyduje o asertowanej wartości** — nie: czy rozgałęzienie gdzieś istnieje.

Warunek sprawdza się czterema liczbami, nie trzema: wierszy przed, wierszy po, suma ubytków z tabeli oraz `diff` listy ograniczony do wierszy spoza **obu** tabel z „Uwag" — tej wyżej i tej z etykietami zmienionymi w YQM-22 — **pusty w obie strony**, bo wariant ze stałą dodaje wiersze bez etykiety, a nie tylko usuwa. Trzy pierwsze liczby mogą się domknąć przypadkiem, gdy coś zniknie i coś przybędzie; czwarta tego nie przepuści. Wyłączenie obejmuje obie tabele, bo osiem wierszy z drugiej zmieniło etykiety w YQM-22 i różni się od baseline z tego powodu; warunek wykonany dosłownie na jednej tabeli pokazuje te osiem wierszy i wygląda na niezgodność, którą nie jest:

```
cat > /tmp/qm-excluded.txt <<'EOF'
ConnectionListTest::testConnectionWithoutDsnIsSkippedWithoutConnecting
ConnectionListTest::testListedConnectionIsNotOpenedByBootstrap
FileAdapterComponentTest::testBadFileSettingDisablesThePackageBeforeTheCommandSwap
FinalizationTest::testRequestWithoutQueriesSendsNothing
ProtectionTest::testDisabledPackageLeavesNoTrace
QueryMonitorComponentTest::testDisabledChangesNothingAndSendsNothing
QueryMonitorComponentTest::testRequestWithoutQueriesSendsNothing
QueryMonitorComponentTest::testBadConfigurationDisablesPackageWithOneError
TestApplicationTest::testApplicationRunsInAnotherProcess
SqlNormalizerTest::testLimitSmallerThanEllipsisIsRejected
SqlNormalizerTest::testUnsupportedDbGivesNull
EOF

docker compose run --rm --no-deps -e QM_MYSQL_DSN= -e QM_PGSQL_DSN= php \
    vendor/bin/phpunit --list-tests \
  | sed -n 's/^ - //p' | sed -E 's/^([A-Za-z0-9_\\]*\\)?//' | LC_ALL=C sort > /tmp/qm-after.txt

diff <(grep -vFf /tmp/qm-excluded.txt <(git show 8710462:tests/baseline-yqm19.txt)) \
     <(grep -vFf /tmp/qm-excluded.txt /tmp/qm-after.txt)
```

To jest komenda uruchomiona, nie przepisana. Baseline czyta się przez `git show`, a nie ze ścieżki w drzewie: YQM-26 te pliki usuwa, więc wersja ze ścieżką przestawałaby działać dokładnie w chwili, w której zadanie zostaje odhaczone. Jedenaście nazw stoi wprost, bo zastępnik nie da się wykonać, a rozjazd między tą listą a tabelami widać wtedy gołym okiem; wyciąganie listy z tabel `grep`-em po samym dokumencie wiązałoby warunek z formatowaniem kolumn. Nazwy są w postaci `Klasa::metoda`, bo `grep -vFf` dopasowuje podciągi: sama nazwa metody wycięłaby też każdy dłuższy identyfikator z tym prefiksem, a `testRequestWithoutQueriesSendsNothing` występuje w dwóch klasach. `--no-deps` i puste DSN są konieczne — bez nich compose podniesie bazy, których ta komenda nie potrzebuje. Blok uruchamia się przez `bash`: podstawienia procesu `<(...)` nie ma w POSIX-owym `sh`, a domyślna powłoka obrazu to `dash`, więc `sh -c` odpowie `Syntax error: "(" unexpected` i nie powie, że chodzi o powłokę.

Końcowej liczby wierszy plan nie przyjmuje z góry, tylko mierzy ją przy odbiorze. Zmierzone przy odbiorze YQM-24: **349 → 334** — siedem metod traci providera w całości (−7), `testBadFileSettingDisablesThePackageBeforeTheCommandSwap` 12 → 6 (−6), `testBadConfigurationDisablesPackageWithOneError` 4 → 2 (−2), razem −15. Rozjazd wobec tej wartości jest sygnałem, nie warunkiem: warunkiem są cztery liczby wyżej.

Miarą odbioru jest liczba testów i zestawów, nie suma asercji: sonda z [ADR 0005](../adr/0005-adapter-plikowy-z-blokada-i-utrata-paczki.md) traci losową liczbę paczek na zajętej blokadzie, więc `FileAdapterConcurrencyTest` z założenia nie daje stałej liczby asercji. Trzy biegi niezmienionego zestawu dały 2652, 2654 i 2656 asercji przy niezmiennych 334 testach; wahanie siedzi w testsuite `Process` i nie jest objawem niczego.

Czwarta liczba nie jest formalnością. Tabela sprzed audytu dawała tę samą sumę 32 → 16 przy **innym zbiorze wierszy**: wypadł z niej `testEachFileKeyOverridesOnlyItself` (−6/−3), a doszły trzy metody po 2. Kto sprawdza tylko sumę, nie zauważy, że zmienił się cały zbiór.

Bieg z ustawionym wyłącznie `QM_PGSQL_DSN` pominąłby metody z `ANY_DB` i przy `failOnSkipped` byłby czerwony; compose i CI ustawiają oba DSN, więc dziś takiego biegu nie ma. To znane ograniczenie mechanizmu, nie jego wada.

Osiem wierszy zmieniło etykietę w YQM-22, bez utraty silnika i bez zmiany liczby zestawów — to jedyna różnica wobec baseline w całym etapie poza tabelą wyżej:

| Metoda | przed | po |
|---|---|---|
| `SqlNormalizerTest::testLimitSmallerThanEllipsisIsRejected` | `#0`, `#1`, `#2` | `zero`, `shorter than the ellipsis`, `negative` |
| `SqlNormalizerTest::testUnsupportedDbGivesNull` | `#0`..`#4` | `sqlite`, `mongodb`, `oci`, `empty name`, `mysql in upper case` |

Poza tabelą nic nie traci drugiego silnika. Dwa testy zostają na obu silnikach pod trzecią przesłanką reguły 10, każdy z nazwanym powodem — nienazwany test tej przesłanki nie dostaje:

| Test | Powód |
|---|---|
| `TestApplicationTest::testRouteWithoutQueriesGivesNoBatch` (`:81-89`) | konstrukcja „brak zapytań = brak paczki" ma w zestawie trzy instancje; ta jedna idzie przez `AppRunner` i prawdziwą trasę `order/none`, pozostałe dwie (`QueryMonitorComponentTest:103`, `FinalizationTest:69`) są jej bliźniakami na poziomie komponentu i cyklu życia i są w tabeli. Jedno potwierdzenie, nie zero i nie trzy |
| `TestApplicationTest::testSchemaIsCreatedAndCreationIsIdempotent` (`:18-28`) | jedyny test, którego przedmiotem jest sam fixture: wywołuje `Schema::create()` dwa razy (`:23-24`) — żaden inny test w zestawie tego nie robi (`grep -rn "Schema::create" tests/`). Wymiar bazy jest tu realny, bo DDL różni się tekstem per sterownik (`tests/app/Schema.php:27`, `SERIAL PRIMARY KEY` wobec `INT AUTO_INCREMENT PRIMARY KEY`), a `IF NOT EXISTS` to semantyka każdego silnika osobno. Bez niego zepsuty DDL wychodzi jako mylna diagnoza „pakiet nie działa" zamiast „fixture nie działa" |

`ReadmeExampleTest::testConfigurationExampleRunsInTheTestApplication` zostaje na obu bazach krokiem 1: tworzy schemat, wykonuje żądanie `order/index` i czyta zapisaną paczkę (`:38-45`).

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
