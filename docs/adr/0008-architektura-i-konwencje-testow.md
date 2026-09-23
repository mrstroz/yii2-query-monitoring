# ADR-0008: Architektura testów i wersja PHPUnit

| Pole | Wartość |
|---|---|
| **Status** | Zaakceptowany |
| **Data** | 2026-09-22 |
| **Dotyczy** | zestawu testów pakietu i zależności developerskich |

## Kontekst

Po E0 i E1 zestaw testów urósł do około 4600 linii i przestał mieć jednolitą zasadę podziału. `tests/Unit/adapter/FileAdapterTest.php` uruchamia osobny proces przez `proc_open`, ustawia `ulimit -f` przez `sh -c`, bierze `flock`, pisze do `/dev/full` i ustawia `open_basedir` — a leży w katalogu testów jednostkowych. `tests/Integration/` miesza testy, których przedmiotem jest odpowiedź aplikacji Yii, z sondą ośmiu równoległych procesów i z testem samego runnera procesów. `phpunit.xml.dist` ma dwa testsuite'y odpowiadające dwóm katalogom, więc nie da się uruchomić części niewymagającej baz — a przy `failOnSkipped="true"` brak DSN wywraca cały bieg.

Nazwy data providerów mają cztery style, część zestawów danych nie ma nazw, a jedna asercja sprawdza pełny natywny komunikat systemu (`fwrite(): Write of …`, `No space left on device`), choć [spec 03 §2](../spec/03-adaptery-wyjsciowe.md#2-błąd-adaptera) wymaga jedynie rodzaju operacji i ścieżki.

Osobno stoi pytanie o wersję PHPUnit. Pakiet wymaga PHP 8.1 (`composer.json`), a `phpunit/phpunit:^10.5` to ostatnia główna wersja zgodna z PHP 8.1; PHPUnit 11 wymaga co najmniej PHP 8.2. Macierz CI (`.github/workflows/ci.yml`) obejmuje PHP 8.1–8.4 oraz osobny bieg z najniższymi wersjami zależności.

Kolejne etapy dokładają MongoDB (E3) i tryb konsolowy (E4), czyli dwa nowe źródła testów. Bez rozstrzygnięcia podziału powstaną w tym samym układzie, w którym dziś nie wiadomo, gdzie test ma leżeć.

## Decyzja

Testy dzielimy według zależności środowiskowych na `Unit`, `Integration/Yii`, `Integration/Filesystem` i `Integration/Process`, a o katalogu decyduje kontrakt, który test sprawdza, nie użyty mechanizm. Pozostajemy na `phpunit/phpunit:^10.5` tak długo, jak minimalną wersją PHP jest 8.1.

Rozstrzygnięcia szczegółowe:

1. `phpunit/phpunit:^10.5` zostaje, dopóki minimalną wersją PHP jest 8.1.
2. PHPUnit 10 jest ostatnią główną wersją zgodną z PHP 8.1; PHPUnit 11 wymaga PHP 8.2.
3. Sam PHPUnit nie określa stylu ani architektury testów — to, co niżej, jest decyzją projektu, nie narzędzia.
4. Po usunięciu PHP 8.1 z publicznie wspieranej macierzy oceniamy ponownie i planujemy przejście na najnowszą wspieraną wersję PHPUnit zgodną z nowym minimalnym PHP.
5. Nie wprowadzamy złożonego constraintu obsługującego kilka głównych wersji PHPUnit zależnie od wersji PHP.
6. Podział według zależności środowiskowych:
   - **Unit** — bez bazy, bez osobnego procesu, bez pełnej aplikacji Yii. Test jednostkowy nie zapisuje plików, nie tworzy katalogów tymczasowych i nie bierze blokad; odczyt fixture'u z repozytorium jest dozwolony.
   - **Integration/Yii** — prawdziwa aplikacja Yii, opcjonalnie MySQL albo PostgreSQL.
   - **Integration/Filesystem** — prawdziwe pliki, katalogi, blokady, uprawnienia i rotacja.
   - **Integration/Process** — procesy potomne, bariery, timeouty, sygnały i kody wyjścia.
7. Test korzystający z `/dev/full`, `flock`, `open_basedir`, `sh`, `ulimit`, `proc_open` albo osobnego procesu nie jest testem jednostkowym.
8. O katalogu decyduje badany kontrakt, nie mechanizm. Proces potomny użyty wyłącznie jako nośnik nieodwracalnego ustawienia (`ulimit -f`, `open_basedir`) albo jako model żądania PHP-FPM nie czyni testu testem procesów.
9. Testy zależne od systemu operacyjnego są dopuszczalne, gdy sprawdzają istotny kontrakt i biegną w określonym środowisku Docker albo CI.
10. Rodzaj bazy parametryzujemy tylko wtedy, gdy zachowanie przechodzi przez sterownik albo implementację zależną od bazy, albo gdy świadomie potwierdzamy wsparcie obu baz.
11. Jeden test może mieć wiele asercji, ale wszystkie opisują jedno obserwowalne zachowanie.

Punkt 8 jest tym, który rozstrzyga dzisiejsze przypadki graniczne: `FileAdapterTest` idzie do `Filesystem` mimo `proc_open`, bo jego przedmiotem jest zapis pliku; `FileAdapterProtectionTest` idzie do `Yii`, bo jego przedmiotem jest odpowiedź aplikacji; `FileAdapterConcurrencyTest` i `ProcessGroupTest` idą do `Process`, bo ich przedmiotem są równoległe procesy i sam runner.

Operacyjne reguły pisania testów — nazwy, providery, helpery, komentarze, układ — są w [`tests/README.md`](../../tests/README.md), nie tutaj.

## Konsekwencje

**Pozytywne:** wiadomo, gdzie położyć nowy test MongoDB i konsoli, zanim powstanie. Cztery testsuite'y pozwalają uruchomić `Unit,Filesystem,Process` bez baz, co przy `failOnSkipped="true"` dziś nie jest możliwe. Katalog `tests/Unit` znów mówi prawdę o tym, czego test wymaga.

**Negatywne:** PHPUnit 10 nie dostaje już regularnych poprawek błędów ([phpunit.de/supported-versions.html](https://phpunit.de/supported-versions.html), odczytane 2026-09-23) — zostaje zależnością developerską, bo jest ceną za PHP 8.1, i przez cały czas życia tej decyzji nie skorzystamy z API PHPUnit 11 ani 12. Podział na cztery katalogi to więcej ruchów przy dodawaniu testu, który dotyka dwóch obszarów, i jednorazowa zmiana namespace'ów w całym zestawie.

**Wymagania:** `phpunit.xml.dist` z czterema testsuite'ami, `composer.json` z autoload-dev pokrywającym nowe katalogi, spisane konwencje w `tests/README.md`. Kolejność wykonania jest w [`plan/03-testy.md`](../plan/03-testy.md).

## Rozważane warianty

| Wariant | Dlaczego odrzucony |
|---|---|
| Zostawić obecną strukturę | Katalog `tests/Unit` zawiera test biorący blokady i uruchamiający procesy, więc nazwa katalogu nie niesie informacji. E3 i E4 powieliłyby ten stan w dwóch nowych obszarach |
| Wszystko do jednego katalogu `Integration` | Znika jedyne tanie uruchomienie bez baz i bez środowiska; każdy bieg lokalny wymagałby MySQL i PostgreSQL |
| Testować kilka głównych wersji PHPUnit naraz | Constraint zależny od wersji PHP plus podwójna macierz CI; koszt utrzymania bez korzyści, bo API, którego używamy, jest wspólne |
| Podnieść minimalne PHP do 8.2 tylko po to, żeby podnieść PHPUnit | Odcina użytkowników PHP 8.1 z powodu wygody narzędzia developerskiego. Minimalne PHP wyznaczają odbiorcy pakietu, nie zależności `require-dev` |
| Dzielić testy według mechanizmu (osobny proces ⇒ `Process`) | `AppRunner` uruchamia proces w każdym teście aplikacji Yii, więc prawie cały `Integration` wylądowałby w `Process`. Stąd punkt 8 |

## Kiedy wrócić do tej decyzji

Gdy PHP 8.1 wypadnie z publicznie wspieranej macierzy pakietu — wtedy punkty 1, 2 i 5 tracą podstawę i planujemy przejście na aktualną wersję PHPUnit. Gdy dojdzie źródło danych, którego test nie mieści się w żadnym z czterech katalogów, albo gdy okaże się, że podział po kontrakcie zostawia przypadki, w których dwoje ludzi wskazuje różne katalogi.
