# E4b. Pełniejszy `query` MongoDB

**Cel:** zapytania MongoDB z rzeczywistej aplikacji mają tekst `query` zamiast `null`: głęboko zagnieżdżony potok Atlas Search, `distinct` i polecenie dłuższe niż `maxQueryLength`.

**Koniec etapu:** potok `$searchMeta` z `facet.operator.compound` i compound w compound, `distinct` z filtrem oraz `geoWithin` z wielokątem ponad `maxQueryLength` dają niepusty `query` w testach tabelarycznych `MongoDbNormalizer`.

**Zależności zewnętrzne:** brak.

## Zadania

- [x] (^) **YQM-43** Dokumenty MongoDB czytane na każdej głębokości
      Spec: [02 §4](../spec/02-format-paczki.md#4-normalizacja) · ADR: [0004](../adr/0004-normalizacja-literalow-na-znak-zapytania.md)
      Gotowe, gdy: potok Atlas Search z compound w compound i z `embeddedDocument` daje w testach `MongoDbNormalizer` tekst z każdym kluczem.
      W rzeczywistej aplikacji 2026-09-25 wszystkie 26 wywołań `aggregate` z Atlas Search miały `query: null` przez klucze za piątym poziomem.

- [x] (=) **YQM-44** `distinct` w postaci tekstowej
      Spec: [02 §4](../spec/02-format-paczki.md#4-normalizacja) · Zależy od: YQM-43
      W rzeczywistej aplikacji 2026-09-25 wszystkie 139 wywołań `distinct` miały `query: null`.

- [x] (=) **YQM-45** Obcięcie zbyt długiego `query` MongoDB
      Spec: [02 §4](../spec/02-format-paczki.md#4-normalizacja) · ADR: [0004](../adr/0004-normalizacja-literalow-na-znak-zapytania.md) · Zależy od: YQM-44
      Gotowe, gdy: polecenie dłuższe niż `maxQueryLength` daje tekst tej długości zakończony `…`, na granicy znaku UTF-8.
