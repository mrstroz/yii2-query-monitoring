# E5a. Próbkowanie paczek

**Cel:** mniej paczek wysyłanych do odbiorcy: część zwykłych paczek i każda paczka diagnostyczna, z prawdopodobieństwem wysłania w nagłówku do ważenia metryk.

**Koniec etapu:** z `sampling` paczka spełniająca włączone kryterium idzie z `rate` `1.0`, a zwykła idzie z `rate` z konfiguracji albo nie trafia do adaptera. Bez `sampling` każda paczka idzie z `sample: null`. Każda paczka ma format `v: 4`.

**Zależności zewnętrzne:** odbiorca musi przyjmować `v: 4` przed aktualizacją pakietu w aplikacji ([spec 02 §7](../spec/02-format-paczki.md#7-próbkowanie-po-stronie-odbiorcy)). Zmiany workera są poza tym repozytorium.

## Zadania

- [x] (^) **YQM-57** Próbkowanie całych paczek w specyfikacji
      Spec: [01 §5.6](../spec/01-zbieranie-danych.md#56-próbkowanie-paczek) · ADR: [0014](../adr/0014-probkowanie-calych-paczek-przed-adapterem.md)
      [ADR 0002](../adr/0002-plaska-lista-zamiast-agregatow.md) i spec 00 wykluczały próbkowanie, więc zakres zmienia się przed kodem.

- [x] (^) **YQM-58** Decyzja o paczce przed adapterem i pole `sample` w formacie `v: 4`
      Spec: [01 §5.6](../spec/01-zbieranie-danych.md#56-próbkowanie-paczek), [02 §1](../spec/02-format-paczki.md#1-nagłówek) · Zależy od: YQM-57
      Gotowe, gdy: testy jednostkowe z wstrzykniętym losowaniem pokazują wysłanie i pominięcie zwykłej paczki, każde kryterium na granicy progu i luki w `seq` bez zmiany `dropped`.

- [x] (=) **YQM-59** Próbkowanie i format `v: 4` w README
      Spec: [02 §7](../spec/02-format-paczki.md#7-próbkowanie-po-stronie-odbiorcy) · Zależy od: YQM-58
      README ma przykład z 10% zwykłych paczek i kryteriami oraz ostrzeżenie o odbiorcy przyjmującym tylko `v: 3`.

Do E6: nagłówek nowego bufora jest kodowany do JSON do trzech razy (konstruktor, `reserveSample()`, `setRoute()` w `Context::newCollector()`). To koszt raz na paczkę, nie na zapytanie; test wydajności E6 pokaże, czy pomiar leniwy jest potrzebny (przegląd kodu YQM-57..59, 2026-10-01).
