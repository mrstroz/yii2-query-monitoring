# E4. Źródło MongoDB

**Cel:** wpisy z `yii\mongodb\Connection` przez zdarzenia sterownika, z normalizacją filtrów i potoków.

**Koniec etapu:** aplikacja z MySQL i MongoDB daje jedną paczkę z wpisami z obu baz, a `writeErrors` w udanym poleceniu daje `result: error`.

**Zależności zewnętrzne:** brak.

## Zadania

Zadania zostaną spisane, gdy E3 się zakończy. Pierwsze zadanie to sonda: czy `yii\mongodb\Connection` udostępnia `Manager` do `addSubscriber` (otwarta kwestia 2 w [spec 00 §9](../spec/00-przeglad-i-zakres.md#9-otwarte-kwestie)). Obowiązkowe scenariusze odbioru: `writeErrors` i `writeConcernError` w udanym poleceniu dają `result: error` z kodem jako tekst, `getMore` daje osobny wpis, `insertMany` podzielony przez sterownik daje tyle wpisów, ile poleceń, a wpis MongoDB ma `caller` z `N` zmierzonym dla ścieżki zdarzeń sterownika, bo tam ślad jest brany w innym miejscu niż w `Recorder::record()` ([ADR 0009](../adr/0009-caller-i-route-w-formacie-v2.md)). Zakres: [spec 01 §3](../spec/01-zbieranie-danych.md#3-źródło-mongodb), [spec 02 §4](../spec/02-format-paczki.md#4-normalizacja).
