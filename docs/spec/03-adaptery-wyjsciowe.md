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

Używany, gdy `adapter` jest `null`.

| Klucz `file` | Znaczenie | Wartość początkowa |
|---|---|---|
| `path` | Ścieżka pliku | `@runtime/logs/query-monitoring.jsonl` |
| `maxSize` | Rozmiar, po którym następuje rotacja | `10485760` |
| `maxFiles` | Liczba kopii archiwalnych | `5` |

| Co | Zachowanie |
|---|---|
| Format | Jedna paczka to jeden wiersz JSON, bez wcięć |
| Blokada | `flock` z `LOCK_EX \| LOCK_NB` na osobnym pliku `<path>.lock`, wspólna dla zapisu i rotacji |
| Blokada zajęta | Paczka przepada |
| Rotacja | Po zapisie, gdy rozmiar przekracza `maxSize`. Kopie `.1` do `.<maxFiles>`, najstarsza usuwana |
| Plik niedostępny | Aplikacja działa dalej. Jeden `Yii::error` na proces |
| Dysk | Tylko lokalny. NFS poza zakresem |

## 4. Test wydajności

Kryterium odbioru 6 z [00 §6](00-przeglad-i-zakres.md#6-kryteria-sukcesu).

| Parametr | Wartość |
|---|---|
| Zapytań na żądanie | 200 |
| Żądań | 1000 |
| Współbieżność | 8 procesów PHP-FPM |
| Warianty | Pakiet wyłączony, pakiet włączony z normalizacją i adapterem plikowym |
| Metryki | Mediana i p95 czasu odpowiedzi, `memory_get_peak_usage(true)` na końcu żądania |
| Próg | Do 5% na medianie i p95, do 2 MB pamięci szczytowej |

Wynik decyduje o wartościach początkowych limitów z [02 §5](02-format-paczki.md#5-limity).

## 5. Poza zakresem

Adapter do konkretnego workera, kolejki lub HTTP. Kompresja plików. Odczyt plików przez inny proces.
