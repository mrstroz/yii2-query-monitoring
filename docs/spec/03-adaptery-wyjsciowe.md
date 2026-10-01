# 03. Adaptery wyjściowe

Gdzie trafia gotowa paczka. Format paczki jest w [02](02-format-paczki.md).

## 1. Kontrakt

```php
interface BatchAdapterInterface
{
    public function send(QueryBatch $batch): void;
}
```

Konfiguracja `adapter` przyjmuje nazwę klasy, obiekt implementujący interfejs albo `callable` z jednym argumentem `QueryBatch`. Adapter jest wywoływany raz na gotową paczkę, nigdy per zapytanie. Paczka pominięta przez próbkowanie ([01 §5.6](01-zbieranie-danych.md#56-próbkowanie-paczek)) nie trafia do adaptera, a adapter nie dostaje o niej żadnej informacji. `QueryBatch` udostępnia dane jako tablicę i jako JSON.

Pakiet nie zna adresu workera, kolejki ani formatu API odbiorcy.

## 2. Błąd adaptera

| Sytuacja | Zachowanie |
|---|---|
| Wyjątek z `send()` | Paczka przepada. Kontekst konsoli albo joba zbiera dalej do następnej paczki z kolejnym `seq` ([01 §5.2](01-zbieranie-danych.md#52-porcjowanie)). Jeden `Yii::error` na proces, bez treści zapytań. Wyjątek własnego adaptera plikowego ([§3](#3-domyślny-adapter-plikowy)) jest logowany z komunikatem, który nazywa operację i ścieżkę, bez treści zapytań i bez JSON paczki; wyjątek innego adaptera tylko z nazwą klasy |
| Ponowienia | Brak |
| Zapis awaryjny | Brak |
| Czas działania | Odpowiada za niego adapter |
| Zapytania wykonane przez adapter | Nie trafiają do kolektora, [01 §6](01-zbieranie-danych.md#6-ochrona-aplikacji) |

Adapter może zostać wywołany z callbacku shutdown, gdy część komponentów Yii jest już zamknięta. Adapter korzystający z innych komponentów musi to uwzględnić.

## 3. Domyślny adapter plikowy

Używany, gdy `adapter` jest `null`. Tylko wtedy klucz `file` jest sprawdzany. Przy własnym adapterze jest pomijany, także gdy jest błędny.

| Klucz `file` | Znaczenie | Wartość początkowa |
|---|---|---|
| `path` | Ścieżka pliku, może zawierać alias Yii. Niepusta | `@runtime/logs/query-monitoring.jsonl` |
| `maxSize` | Rozmiar w bajtach, po którego przekroczeniu następuje rotacja, najmniej `1` | `10485760` |
| `maxFiles` | Liczba kopii archiwalnych, najmniej `1` | `5` |
| `fileMode` | Tryb nadawany plikowi danych i `.lock` przez proces, który je utworzył, liczba całkowita 0–0777 | `0664` |
| `dirMode` | Tryb nadawany katalogowi przez proces, który go utworzył, liczba całkowita 0–0777 | `0775` |

Każdy klucz można nadpisać osobno, a pominięty zachowuje wartość początkową. `path` musi być niepustym tekstem, `maxSize` i `maxFiles` liczbami całkowitymi co najmniej `1`, `fileMode` i `dirMode` liczbami całkowitymi od `0` do `0777`; nieznany klucz to błędna konfiguracja pakietu ([01 §1](01-zbieranie-danych.md#1-komponent-i-konfiguracja)). W bootstrapie adapter tylko sprawdza konfigurację i rozwiązuje alias w `path`. Katalog, blokada i plik powstają przy pierwszym zapisie, tak jak pakiet nie łączy się z bazą w bootstrapie, więc błąd systemu plików pojawia się przy wysyłce, a nie przy starcie.

| Co | Zachowanie |
|---|---|
| Format | Jedna paczka to jeden wiersz: JSON bez wcięć zakończony `\n`, zapisany jednym `fwrite` w trybie dopisywania. `false` lub liczba zapisanych bajtów mniejsza od długości wiersza oznacza błąd zapisu |
| Blokada | `flock` z `LOCK_EX \| LOCK_NB` na osobnym pliku `<path>.lock`, wspólna dla zapisu i rotacji. Plik danych jest otwierany po wzięciu blokady i zamykany przed jej zwolnieniem, osobno przy każdej paczce. Wynik `wouldBlock` odróżnia zajętą blokadę od błędu `flock` |
| Blokada zajęta | Paczka przepada, bez wyjątku i bez `Yii::error` |
| Niepełny zapis | Gdy `fwrite` zapisze tylko część wiersza, adapter pod tą samą blokadą przycina plik do rozmiaru sprzed zapisu i kończy się wyjątkiem. Następna paczka nie dokleja się do urwanego JSON |
| Wynik zapisu | Poza `send()` adapter ma `write(QueryBatch): bool`, które zwraca `false`, gdy paczka przepadła przez zajętą blokadę. `send()` wywołuje `write()` i pomija wynik |
| Rotacja | Po zapisie, gdy rozmiar pliku jest większy niż `maxSize`. Równy `maxSize` nie rotuje. Przesuwane są tylko kopie przed pierwszą brakującą, od najstarszej, przez `rename` na następną pozycję, a na końcu bieżący plik na `.1`. Gdy żadnej nie brakuje, najstarsza `.<maxFiles>` ustępuje miejsca `.<maxFiles-1>`, bez osobnego usuwania. Nieudana rotacja kończy się wyjątkiem i zostawia wolne najpóźniej `.1`, więc ponowienie przenosi tylko bieżący plik i nie usuwa żadnej kopii. Paczka zapisana przed nieudaną rotacją zostaje w pliku, choć `send()` rzucił wyjątek; w konsoli i w jobie następna paczka ma kolejny `seq`, więc w pliku nie ma luki. Paczka większa niż `maxSize` także w pustym pliku od razu trafia do `.1`; następny zapis tworzy nowy plik bieżący |
| Plik ponad `maxSize` przed zapisem | Poprzednia rotacja się nie udała. Adapter pod blokadą najpierw rotuje, a gdy rotacja znów zawiedzie, nie dopisuje paczki i kończy się wyjątkiem. Plik bieżący nie rośnie więc ponad `maxSize` i jedną paczkę |
| Uprawnienia | Proces, który tworzy katalog, plik danych albo `.lock`, nadaje im `dirMode` albo `fileMode` przez `chmod`, niezależnie od `umask`. Katalog zachowuje bit setgid odziedziczony po katalogu nadrzędnym, bo bez niego pliki tworzone później dostałyby główną grupę tworzącego użytkownika. Tak samo każdy brakujący katalog nadrzędny, który proces tworzy po drodze; katalogów, które już istniały, nie zmienia. Pliku utworzonego przez innego użytkownika nie zmienia. Użytkownik serwera WWW i użytkownik konsoli piszą do tych samych plików, gdy należą do jednej grupy, a katalog ma bit setgid; rotacja wymaga prawa zapisu do katalogu. Katalog nie może mieć bitu sticky: jądro z `fs.protected_regular` nie pozwoli wtedy drugiemu użytkownikowi otworzyć `.lock` ani pliku danych, a rotacja nie przeniesie cudzego pliku |
| Katalog | Brakujący katalog pliku jest tworzony, jak w `yii\log\FileTarget`, także bez błędu przy równoległej próbie utworzenia go przez inny proces. Wymaga prawa zapisu do katalogu nadrzędnego |
| Plik niedostępny | Nieudane utworzenie katalogu, otwarcie pliku blokady lub danych, `flock` z przyczyny innej niż zajęta blokada, niepełny zapis albo rotacja kończą się wyjątkiem z `send()`, obsłużonym jak w [§2](#2-błąd-adaptera). Aplikacja działa dalej, a `Yii::error` jest jeden na proces. Adapter nie wypisuje ostrzeżeń PHP, a komunikat wyjątku nie zawiera JSON paczki |
| Dysk | Tylko lokalny. NFS poza zakresem |

## 4. Test wydajności

Kryterium odbioru 6 z [00 §6](00-przeglad-i-zakres.md#6-kryteria-sukcesu).

| Parametr | Wartość |
|---|---|
| Zapytań na żądanie | 200 |
| Żądań | 1000 |
| Współbieżność | 8 procesów PHP-FPM |
| Warianty | Pakiet wyłączony, pakiet włączony z normalizacją i adapterem plikowym |
| Metryki | Mediana i p95 czasu odpowiedzi, `memory_get_peak_usage(true)` na końcu żądania, paczki utracone przez zajętą blokadę (`write()` zwraca `false`) |
| Próg | Do 5% na medianie i p95, do 2 MB pamięci szczytowej |

Wynik decyduje o wartościach początkowych limitów z [02 §5](02-format-paczki.md#5-limity).

## 5. Poza zakresem

Adapter do konkretnego workera, kolejki lub HTTP. Kompresja plików. Odczyt plików przez inny proces. Urwany wiersz, gdy po niepełnym zapisie zawiedzie przycięcie pliku albo proces zostanie przerwany w trakcie zapisu.
