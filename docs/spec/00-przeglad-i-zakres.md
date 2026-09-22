# 00. Przegląd i zakres

## Informacje o dokumencie

| Pole | Wartość |
|---|---|
| **Projekt** | yii2-query-monitoring |
| **Repozytorium** | `github.com/mrstroz/yii2-query-monitoring` |
| **Data utworzenia** | 2026-09-22 |
| **Status** | Szkic, oparty na briefie. Kod powstaje w etapie E0, od YQM-1. |

---

## 1. Cel

Kilka aplikacji Yii 2 wykonuje zapytania do MySQL, PostgreSQL i MongoDB, a nikt nie widzi, ile ich jest w jednym żądaniu, ile trwają i które kończą się błędem. Włączenie `enableProfiling` w Yii na produkcji jest za drogie i zapisuje wartości parametrów do logu.

Biblioteka instalowana przez Composer ma zbudować jedną paczkę na żądanie HTTP i serię paczek na zadanie konsolowe. Paczka to nagłówek i płaska lista zapytań z czasem i wynikiem. Paczkę odbiera adapter wskazany przez aplikację. Domyślnie jest to plik JSON Lines z rotacją.

Paczka ma odpowiadać na trzy pytania:

1. Ile zapytań i do których baz wykonała ta akcja.
2. Które z nich trwały długo.
3. Które zakończyły się błędem i jakim kodem.

## 2. Główna zasada

**Biblioteka nigdy nie zmienia wyniku operacji bazodanowej ani odpowiedzi aplikacji.**

Z tego wynika reszta: wyjątek w kolektorze lub adapterze jest przechwytywany i logowany, a nie propagowany. Gdy paczka przekracza limit, wpisy przepadają i są liczone, zamiast blokować żądanie. Gdy plik jest zajęty, paczka przepada, zamiast czekać. Wartości parametrów nie trafiają do paczki, więc biblioteka nie może stać się kanałem wycieku danych.

## 3. Zakres wersji 1

| Obszar | W zakresie |
|---|---|
| Źródła SQL | `yii\db\Connection` dla MySQL i PostgreSQL, przez podmienioną klasę `Command` |
| Źródło MongoDB | `yii\mongodb\Connection`, przez zdarzenia sterownika `ext-mongodb` |
| Konteksty | Żądanie HTTP w PHP-FPM, zadanie konsolowe `php yii ...` |
| Dane | Wszystkie polecenia wysłane do bazy, bez progu czasu i bez próbkowania |
| Paczka | Nagłówek z akcją wejściową, płaska lista wpisów, licznik pominiętych |
| Normalizacja | Usunięcie wartości z SQL i z dokumentów MongoDB |
| Adapter | Kontrakt `send(QueryBatch): void`, obiekt lub `callable` |
| Adapter plikowy | JSON Lines w `runtime/logs`, rotacja po rozmiarze, wspólna blokada |

## 4. Poza zakresem wersji 1

| Element | Uzasadnienie |
|---|---|
| Agregaty: grupy, histogramy, sumy | Odbiorca paczek liczy je sam z płaskiej listy. Dodanie później to nowe pole, nie przebudowa |
| Próg wolnego zapytania i próbkowanie | Lista ma być pełna. Filtrowanie robi odbiorca |
| Worker, wysyłka do konkretnego systemu, dashboard | Osobne prace. Biblioteka daje tylko punkt podłączenia adaptera |
| `begin`, `commit`, `rollback` | Yii wykonuje je bezpośrednio przez PDO, poza `Command`. Dodanie wymaga podpięcia pod zdarzenia `Connection` |
| Rozpoznawanie jobów kolejki | Worker to jedno długie zadanie z wieloma paczkami. Wymaga zależności od konkretnej kolejki |
| RoadRunner, Swoole dla HTTP | Wymaga resetu stanu kolektora między żądaniami |
| NFS i współdzielone wolumeny | Blokada `flock` na NFS nie jest wiarygodna |
| Własne klasy `Command`, dynamiczne połączenia | Podmiana klasy działa tylko dla połączeń z konfiguracji |
| Błędy przy odczycie kursora SQL | Pomiar kończy się na `execute`. `fetch` jest poza `Command` |
| `sql_mode` z `ANSI_QUOTES` lub `NO_BACKSLASH_ESCAPES` | Aplikacje używają domyślnego trybu MySQL 8. Wykrywanie wymaga zapytania na połączenie |

## 5. Użytkownicy

| Rola | Potrzeba |
|---|---|
| Programista aplikacji Yii | Instaluje pakiet, wpisuje trzy linie konfiguracji, nie zmienia modeli |
| Osoba diagnozująca produkcję | Otwiera plik JSON Lines i widzi, co dana akcja zrobiła w bazie |
| Przyszły worker | Czyta paczki w stałym formacie z numerem wersji |

## 6. Kryteria sukcesu

1. Seria operacji daje pełną listę wpisów z czasami i wynikami, w kolejności zakończenia, do pierwszego osiągniętego limitu. Nadwyżka jest zliczona w `dropped`.
2. Operacje z różnymi wartościami parametrów dają ten sam `query`. Paczka nie zawiera tych wartości.
3. Active Record i zwykłe polecenia są mierzone bez zmian w kodzie aplikacji. Trafienie w cache Yii nie daje wpisu.
4. Domyślny adapter zapisuje poprawne paczki, rotuje pliki i nie przerywa aplikacji przy błędzie zapisu.
5. Własny adapter zastępuje plikowy bez zmian w kolektorze.
6. Narzut w teście z [03 §4](03-adaptery-wyjsciowe.md#4-test-wydajności) mieści się w 5% na medianie i p95 czasu odpowiedzi oraz w 2 MB pamięci szczytowej.

## 7. Środowiska

| Środowisko | Gdzie | Uwagi |
|---|---|---|
| Lokalne | Docker: MySQL 8, PostgreSQL 16, MongoDB 7 | Testy integracyjne |
| CI | GitHub Actions | Matryca PHP 8.1 do 8.4 |
| Produkcja | Aplikacje Yii 2 na PHP-FPM | Każda aplikacja podaje własne `app` w konfiguracji |

Wymagania: PHP 8.1 lub nowszy, Yii 2.0.55 lub nowszy. Starsze wydania 2.0.x mają security advisories, przez które domyślna polityka Composera 2.10 ich nie instaluje. `yiisoft/yii2-mongodb` i `ext-mongodb` są zależnościami opcjonalnymi w `suggest`. Aplikacja tylko z SQL instaluje pakiet bez MongoDB.

## 8. Słownik

| Termin | Znaczenie |
|---|---|
| Paczka | Jeden obiekt JSON: nagłówek plus lista wpisów. Jedna na żądanie HTTP, wiele na zadanie konsolowe |
| Wpis | Jedno polecenie faktycznie wysłane do bazy: `db`, `conn`, `op`, `query`, `time_ms`, `result` |
| Kolektor | Obiekt w pamięci, który przyjmuje wpisy, pilnuje limitów i buduje paczkę |
| Finalizacja | Zamknięcie kolektora i przekazanie paczki do adaptera. Po niej kolektor nie przyjmuje wpisów |
| Adapter | Obiekt lub `callable` z metodą `send(QueryBatch): void`, odbiorca paczki |
| Akcja wejściowa | Pierwsza akcja kontrolera w żądaniu, zapamiętana z `EVENT_BEFORE_ACTION` aplikacji |
| Normalizacja | Zamiana tekstu zapytania na postać bez wartości |
| Zadanie konsolowe | Jedno uruchomienie `php yii ...`, od startu do końca procesu |
| Połączenie monitorowane | Komponent `Connection` z listy w konfiguracji pakietu |

## 9. Otwarte kwestie

| # | Kwestia | Warianty | Co to rozstrzygnie |
|---|---|---|---|
| 1 | Nazwa pakietu i namespace | **Rozstrzygnięte 2026-09-22 w YQM-1:** `mrstroz/yii2-query-monitoring` z `mrstroz\querymonitoring` | Zamknięte |
| 2 | Czy `yii\mongodb\Connection` udostępnia `Manager` sterownika tak, żeby dało się podpiąć `CommandSubscriber` bez podmiany klasy połączenia | `addSubscriber` na `Manager`, albo podmiana klasy `Connection` | Sonda w pierwszym zadaniu etapu E2 |
| 3 | Limity: 500 wpisów, 256 KB, 2 KB na `query`, 10 MB × 5 plików | Zostają, albo korekta | Wynik testu wydajności w E4 |
| 4 | Organizacja normalizatora SQL: jedna klasa z parametrem dialektu, czy osobna klasa na dialekt | **Rozstrzygnięte 2026-09-22 w YQM-6:** jedna klasa `SqlNormalizer` z wewnętrznym enum dialektu niosącym reguły z [02 §4](02-format-paczki.md#4-normalizacja) | Zamknięte |
