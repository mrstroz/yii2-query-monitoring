# E4a. Zwijanie list w `query`

**Cel:** ta sama struktura zapytania daje ten sam `query` niezależnie od liczby wartości w liście `IN` i od numeracji parametrów Yii.

**Koniec etapu:** `where(['material_num' => $ids])` z 3 i z 211 wartościami, z warunkiem po liście, daje jeden `query` w MySQL i PostgreSQL, a `$in` z 2 i 3 wartościami daje jeden `query` w MongoDB.

**Zależności zewnętrzne:** brak.

## Zadania

- [x] (^) **YQM-41** Parametry `:qpN` jako `?` i zwinięte listy `IN` w SQL
      Spec: [02 §4](../spec/02-format-paczki.md#4-normalizacja) · ADR: [0011](../adr/0011-zwijanie-list-in-i-parametrow-yii.md)
      Gotowe, gdy: testy tabelaryczne `SqlNormalizer` pokrywają nowe wiersze spec 02 §4 dla MySQL i PostgreSQL, także krotki, `NOT IN`, podzapytanie i listę w identyfikatorze w cudzysłowie, a test w `Integration/Yii` pokazuje ten sam `query` dla list zbudowanych przez Yii z 3 i 211 wartościami.
      Na rzeczywistej aplikacji `IN` z 211 parametrami zajmował ponad 1,5 KB `query`, a numery parametrów po liście zależały od jej długości. Lista jest czytana liniowo, nie wyrażeniem regularnym z rekurencją: to drugie wyczerpywało stos JIT PCRE przy 5000 elementach i zostawiało listę bez zmian.

- [ ] (=) **YQM-42** Zwinięte tablice `$in` i `$nin` w MongoDB
      Spec: [02 §4](../spec/02-format-paczki.md#4-normalizacja) · ADR: [0011](../adr/0011-zwijanie-list-in-i-parametrow-yii.md)
      Gotowe, gdy: testy tabelaryczne `MongoDbNormalizer` pokazują `$in` i `$nin` z jedną, dwiema i trzema wartościami, `$in` z dokumentem bez zmian i `$all` bez zmian.
