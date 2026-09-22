# E3. Zadania konsolowe

**Cel:** paczki z `php yii ...` z limitami, `seq` i wysyłką reszty na końcu procesu.

**Koniec etapu:** długie zadanie z 1200 krótkimi zapytaniami poniżej limitu rozmiaru i czasu daje trzy paczki z `seq` 1, 2, 3 i wspólnym `id`, a krótkie zadanie daje jedną paczkę przy zakończeniu.

**Zależności zewnętrzne:** brak.

## Zadania

Zadania zostaną spisane, gdy E2 się zakończy. Obowiązkowe scenariusze odbioru: dokładnie 500 operacji wywołuje adapter, gdy proces nadal działa, podział po `maxBatchBytes` z bieżącym wpisem w następnej paczce, podział po 30 sekundach sprawdzany przy kolejnej operacji, reszta wysłana w shutdown, bezczynny proces bez wysyłki. Zakres: [spec 01 §5](../spec/01-zbieranie-danych.md#5-zadanie-konsolowe), [ADR 0007](../adr/0007-zadanie-konsolowe-to-jeden-proces.md).
