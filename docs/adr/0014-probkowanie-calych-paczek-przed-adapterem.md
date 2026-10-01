# ADR-0014: Próbkowanie całych paczek przed adapterem, format `v: 4`

| Pole | Wartość |
|---|---|
| **Status** | Zaakceptowany |
| **Data** | 2026-09-30 |
| **Dotyczy** | Wysyłka paczek, [spec 01 §5.6](../spec/01-zbieranie-danych.md#56-próbkowanie-paczek), format paczki, [spec 02 §1](../spec/02-format-paczki.md#1-nagłówek) i [§7](../spec/02-format-paczki.md#7-próbkowanie-po-stronie-odbiorcy) |

## Kontekst

Paczki trafiają do workera Cloudflare przez adapter aplikacji. Każda paczka to żądanie HTTP do workera, zapis w kolejce i praca po jego stronie. Większość paczek opisuje zwykły ruch, a diagnozy wymagają głównie paczek z błędem, z wolnym zapytaniem albo z dużą liczbą zapytań.

[ADR-0002](0002-plaska-lista-zamiast-agregatow.md) odrzucił próbkowanie wpisów, bo lista w paczce przestaje być pełna. Próbkowanie całych paczek zostawia każdą wysłaną paczkę pełną.

Odbiorca liczy liczniki, sumy i percentyle z paczek, które dostał. Bez informacji, z jakim prawdopodobieństwem paczka została wysłana, nie da się przeskalować tych wartości na cały ruch. Worker (`query-monitoring/src/batch/batch-schema.ts`, `batchShape()`) przyjmuje tylko `v` równe `2` albo `3` i przepuszcza nieznane pola nagłówka bez ich odczytu. Pole dodane do `v: 3` byłoby więc przyjęte i po cichu pominięte w metrykach.

## Decyzja

Kontekst buduje paczkę jak dotąd. Stos kontekstów tuż przed wywołaniem adaptera decyduje, czy ją wysłać. Paczka spełniająca włączone kryterium diagnostyczne idzie zawsze. Pozostałe idą z prawdopodobieństwem `rate`, a wybór zależy tylko od `id` i `seq` paczki (hash). Niewybrana paczka nie trafia do adaptera. Próbkowanie jest domyślnie wyłączone.

Każda paczka ma format `v: 4` z polem nagłówka `sample`. Pole ma wartość `null`, gdy próbkowanie jest wyłączone. Gdy jest włączone, to obiekt z faktycznym prawdopodobieństwem wysłania tej paczki i powodem. Wersja zmienia się dla wszystkich paczek, także bez próbkowania: tak rozstrzygnął właściciel projektu 2026-09-30.

## Konsekwencje

**Pozytywne:** mniej żądań do workera i mniej pracy po jego stronie. Wysłana paczka jest pełna, a jej wpisy, czasy i `dropped` są prawdziwe. Waga `1/rate` z nagłówka daje odbiorcy nieobciążone oszacowanie liczników i sum, bo kryterium zależy tylko od treści paczki. Ta sama paczka zawsze dostaje tę samą decyzję. Test ustawia wynik losowania bez sięgania po losowość. Jeden format `v: 4` niezależnie od konfiguracji: odbiorca nie musi rozróżniać paczek po ustawieniach aplikacji.

**Negatywne:** koszt zbierania zapytań w PHP zostaje, bo decyzja zapada dopiero po zebraniu paczki. Pominięta paczka przepada i późniejszy błąd w tym samym kontekście jej nie przywróci. W `console` i `job` pominięte paczki zostawiają luki w `seq`, a sumy dla całego procesu nie są znane. Udział wysłanych paczek przekracza `rate`, gdy wiele paczek spełnia kryteria. Aktualizacja pakietu to zmiana niezgodna z obecnym workerem: ten odrzuca każdą paczkę `v: 4`, także bez próbkowania.

**Wymagania:** worker przyjmuje `v: 4` (reguły `v: 3` i pole `sample`), zanim aplikacja zaktualizuje pakiet. Zanim aplikacja włączy próbkowanie, worker waży metryki wagą `1/sample.rate` albo pokazuje wprost, że są to dane z próby ([spec 02 §7](../spec/02-format-paczki.md#7-próbkowanie-po-stronie-odbiorcy)). Kolektor rezerwuje w nagłówku miejsce na najszersze `sample`, żeby paczka nie przekroczyła `maxBatchBytes`.

## Rozważane warianty

| Wariant | Dlaczego odrzucony |
|---|---|
| Próbkowanie pojedynczych wpisów albo próg czasu wpisu | Lista w paczce przestaje być pełna ([ADR-0002](0002-plaska-lista-zamiast-agregatow.md)) |
| Decyzja przy otwarciu kontekstu, przed zbieraniem | Oszczędza zbieranie, ale kryteria (błąd, czas, liczba zapytań) są znane dopiero po zebraniu paczki. Paczka z błędem mogłaby przepaść |
| Losowanie `random_int()` przy każdej wysyłce | Ponowne przetworzenie tej samej paczki mogłoby dać inną decyzję, a test musiałby podmieniać generator |
| Metadane w `v: 3` jako pole opcjonalne | Łamie zasadę „zmiana pola to nowe `v`” ze [spec 02](../spec/02-format-paczki.md). Obecny worker przyjąłby paczkę i po cichu liczył bez wag |
| `v: 4` tylko przy włączonym próbkowaniu, `v: 3` bez niego | Bez próbkowania nic by się nie zmieniło, a obecny worker odrzucałby tylko paczki z próbkowaniem. Właściciel projektu wybrał jedną wersję dla każdego odbiorcy zamiast dwóch równoległych |
| Waga zamiast prawdopodobieństwa w nagłówku | Prawdopodobieństwo `1` dla paczki diagnostycznej jest jednoznaczne. Waga `10` przypisana każdej wysłanej paczce przy `rate` 0,1 zawyżałaby paczki diagnostyczne |

## Kiedy wrócić do tej decyzji

Gdy koszt zbierania w PHP okaże się istotny przy ruchu, którego i tak nie wysyłamy. Wtedy decyzję o zwykłej paczce można przenieść na otwarcie kontekstu, ale kryteria diagnostyczne nadal wymagają zebranej paczki.
