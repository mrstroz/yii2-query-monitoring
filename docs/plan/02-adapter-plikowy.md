# E1. Adapter plikowy

**Cel:** domyślny adapter zapisujący paczki do pliku JSON Lines z rotacją i wspólną blokadą.

**Koniec etapu:** aplikacja bez wskazanego adaptera zapisuje paczki do `runtime/logs`, osiem równoległych procesów nie psuje pliku podczas rotacji, a niedostępny plik nie przerywa żądania.

**Zależności zewnętrzne:** brak.

## Zadania

Zadania zostaną spisane, gdy E0 się zakończy. Obowiązkowe scenariusze odbioru: rotacja przy 8 równoległych procesach bez utraty wiersza poza zajętą blokadą, zajęta blokada gubi paczkę bez wyjątku, katalog bez prawa zapisu daje jeden `Yii::error` i poprawną odpowiedź aplikacji. Zakres: [spec 03 §3](../spec/03-adaptery-wyjsciowe.md#3-domyślny-adapter-plikowy), [ADR 0005](../adr/0005-adapter-plikowy-z-blokada-i-utrata-paczki.md).
