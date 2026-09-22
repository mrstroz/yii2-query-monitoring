# ADR-0002: Płaska lista zapytań zamiast agregatów

| Pole | Wartość |
|---|---|
| **Status** | Zaakceptowany |
| **Data** | 2026-09-22 |
| **Dotyczy** | Format paczki, [spec 02](../spec/02-format-paczki.md) |

## Kontekst

Odbiorca paczek jeszcze nie istnieje, więc nie wiadomo, które agregaty są potrzebne. Każdy agregat liczony w bibliotece wymaga decyzji bez odbiorcy: reguł grupowania po wzorcu, stałych przedziałów histogramu, limitu grup, progu wolnego zapytania. Każda z tych decyzji trafia do formatu paczki i wymaga nowej wersji `v` przy zmianie.

## Decyzja

Paczka to nagłówek i płaska lista wpisów, jeden na polecenie wysłane do bazy, bez agregatów, progów i próbkowania. Kolektor pilnuje tylko limitu wpisów i rozmiaru.

## Konsekwencje

**Pozytywne:** kolektor to tablica w pamięci. Odbiorca liczy dowolne agregaty. Format ma dwanaście pól nagłówka i siedem pól wpisu.

**Negatywne:** paczka rośnie liniowo z liczbą zapytań. Żądanie z 2000 zapytań traci 1500 wpisów. Percentyle wymagają odczytu wszystkich paczek.

**Wymagania:** limity z [spec 02 §5](../spec/02-format-paczki.md#5-limity) i licznik `dropped`.

## Rozważane warianty

| Wariant | Dlaczego odrzucony |
|---|---|
| Grupy z licznikami i histogramem | Reguły grupowania i przedziały histogramu to decyzje bez odbiorcy, który by je zweryfikował |
| Lista plus sumy w nagłówku | Sumy są wyprowadzalne z listy. Rozjazd przy `dropped > 0` |
| Próg czasu i próbkowanie | Lista przestaje być pełna, a pełność jest kryterium sukcesu 1 |

## Kiedy wrócić do tej decyzji

Gdy w produkcji `dropped` jest regularnie większe od zera albo gdy odbiorca potrzebuje percentyli bez czytania wszystkich paczek.
