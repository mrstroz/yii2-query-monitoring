# 03. Adaptery wyjściowe

Gdzie trafia gotowa paczka. Format paczki jest w [02](02-format-paczki.md).

## 1. Kontrakt

```php
interface BatchAdapterInterface
{
    public function send(QueryBatch $batch): void;
}
```

Konfiguracja `adapter` przyjmuje nazwę klasy, obiekt implementujący interfejs albo `callable` z jednym argumentem `QueryBatch`. Adapter jest wywoływany raz na gotową paczkę, nigdy per zapytanie. `QueryBatch` udostępnia dane jako tablicę i jako JSON.

Pakiet nie zna adresu workera, kolejki ani formatu API odbiorcy.

## 2. Błąd adaptera

| Sytuacja | Zachowanie |
|---|---|
| Wyjątek z `send()` | Paczka przepada. Jeden `Yii::error` na proces, bez treści zapytań |
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

Każdy klucz można nadpisać osobno, a pominięty zachowuje wartość początkową. `path` musi być niepustym tekstem, `maxSize` i `maxFiles` liczbami całkowitymi co najmniej `1`; nieznany klucz to błędna konfiguracja pakietu ([01 §1](01-zbieranie-danych.md#1-komponent-i-konfiguracja)). W bootstrapie adapter tylko sprawdza konfigurację i rozwiązuje alias w `path`. Katalog, blokada i plik powstają przy pierwszym zapisie, tak jak pakiet nie łączy się z bazą w bootstrapie, więc błąd systemu plików pojawia się przy wysyłce, a nie przy starcie.

| Co | Zachowanie |
|---|---|
| Format | Jedna paczka to jeden wiersz: JSON bez wcięć zakończony `\n`, zapisany jednym `fwrite` w trybie dopisywania. `false` lub liczba zapisanych bajtów mniejsza od długości wiersza oznacza błąd zapisu |
| Blokada | `flock` z `LOCK_EX \| LOCK_NB` na osobnym pliku `<path>.lock`, wspólna dla zapisu i rotacji. Plik danych jest otwierany po wzięciu blokady i zamykany przed jej zwolnieniem, osobno przy każdej paczce. Wynik `wouldBlock` odróżnia zajętą blokadę od błędu `flock` |
| Blokada zajęta | Paczka przepada, bez wyjątku i bez `Yii::error` |
| Niepełny zapis | Gdy `fwrite` zapisze tylko część wiersza, adapter pod tą samą blokadą przycina plik do rozmiaru sprzed zapisu i kończy się wyjątkiem. Następna paczka nie dokleja się do urwanego JSON |
| Wynik zapisu | Poza `send()` adapter ma `write(QueryBatch): bool`, które zwraca `false`, gdy paczka przepadła przez zajętą blokadę. `send()` wywołuje `write()` i pomija wynik |
| Rotacja | Po zapisie, gdy rozmiar pliku jest większy niż `maxSize`. Równy `maxSize` nie rotuje. Bieżący plik przechodzi do `.1`, starsze kopie są przesuwane najwyżej do `.<maxFiles>`, a dotychczasowa `.<maxFiles>` znika. Paczka większa niż `maxSize` także w pustym pliku od razu trafia do `.1`; następny zapis tworzy nowy plik bieżący |
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
