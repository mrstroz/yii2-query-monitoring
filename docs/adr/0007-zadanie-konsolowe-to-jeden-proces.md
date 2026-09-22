# ADR-0007: Zadanie konsolowe to jedno uruchomienie procesu

| Pole | Wartość |
|---|---|
| **Status** | Zaakceptowany |
| **Data** | 2026-09-22 |
| **Dotyczy** | Zadania konsolowe, [spec 01 §5](../spec/01-zbieranie-danych.md#5-zadanie-konsolowe) |

## Kontekst

Aplikacje uruchamiają krótkie komendy `php yii sync/contacts` i długie workery kolejki, które w jednym procesie obsługują tysiące jobów. Yii nie ma pojęcia „job”. Rozpoznanie granic joba wymaga zdarzeń konkretnej kolejki, np. `yii2-queue`, i zależności od niej.

## Decyzja

Zadanie to jedno uruchomienie procesu, od startu do końca, z jednym `id`. Kolektor wysyła paczkę po osiągnięciu limitu wpisów, rozmiaru lub odstępu czasu, sprawdzanego przy kolejnej operacji, a na końcu procesu wysyła resztę. Worker to jedno zadanie z wieloma paczkami numerowanymi `seq`.

## Konsekwencje

**Pozytywne:** brak zależności od kolejki. Jeden mechanizm dla komendy i workera. Zero timerów i sygnałów.

**Negatywne:** paczka workera nie mówi, który job wykonał zapytanie. Bezczynny worker nie wysyła, więc ostatnie wpisy czekają do kolejnej operacji lub końca procesu.

**Wymagania:** `id` wspólne dla wszystkich paczek zadania, `seq` od 1.

## Rozważane warianty

| Wariant | Dlaczego odrzucony |
|---|---|
| Job w `yii2-queue` jako zadanie | Zależność od jednej kolejki. Aplikacje z inną kolejką nie skorzystają |
| Ręczne API `flush()` i `newTask()` | Wymaga zmian w kodzie aplikacji. Można dodać później bez zmiany formatu |
| Timer przez `pcntl_alarm` | Rozszerzenie `pcntl` nie jest dostępne wszędzie i koliduje z sygnałami workera |

## Kiedy wrócić do tej decyzji

Gdy diagnoza workera wymaga przypisania zapytań do joba. Wtedy dochodzi ręczne API, bez zmiany tej decyzji.
