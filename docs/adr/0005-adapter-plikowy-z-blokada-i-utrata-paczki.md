# ADR-0005: Adapter plikowy JSON Lines z blokadą nieblokującą

| Pole | Wartość |
|---|---|
| **Status** | Zaakceptowany |
| **Data** | 2026-09-22 |
| **Dotyczy** | Domyślny adapter, [spec 03 §3](../spec/03-adaptery-wyjsciowe.md#3-domyślny-adapter-plikowy) |

## Kontekst

Wiele procesów PHP-FPM zapisuje do jednego pliku. Rotacja przez `rename` w jednym procesie i `fwrite` w drugim bez wspólnej blokady daje zapis do już przeniesionego pliku. `yii\log\FileTarget` blokuje sam plik logu przez `flock`, ale rotuje bez blokady, co jest znanym źródłem utraty wierszy. Główna zasada zabrania blokowania żądania przez monitoring.

## Decyzja

Jedna paczka to jeden wiersz JSON. Zapis i rotacja biorą `flock` z `LOCK_EX | LOCK_NB` na osobnym pliku `.lock`. Gdy blokada jest zajęta, paczka przepada. Plik tylko na lokalnym dysku.

## Konsekwencje

**Pozytywne:** żądanie nie czeka na zajętą blokadę. Sam zapis i rotacja nadal zajmują czas procesu, mierzony w E4. Rotacja i zapis nie mogą się przeplatać. Format czytelny przez `jq` i przez przyszły worker.

**Negatywne:** przy dużym ruchu część paczek przepada bez śladu w pliku. NFS jest poza zakresem, bo `flock` na NFS nie jest wiarygodny.

**Wymagania:** katalog `runtime/logs` z prawem zapisu dla procesów FPM i konsoli.

## Rozważane warianty

| Wariant | Dlaczego odrzucony |
|---|---|
| Blokujący `flock` | Proces czeka na monitoring. Sprzeczne z główną zasadą |
| Plik tymczasowy plus `rename`, bez blokady | Działa na NFS, ale daje wiele plików na sekundę i wymaga sprzątania |
| Blokada na samym pliku danych | Po rotacji stary uchwyt wskazuje przeniesiony plik, blokada nie chroni rotacji |

## Kiedy wrócić do tej decyzji

Gdy pomiar pokaże utratę paczek przez zajętą blokadę powyżej ułamka procenta albo gdy aplikacja musi pisać na współdzielony wolumen.
