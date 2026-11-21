---
paths:
  - 'app/Services/Deliveries/*ReportSource.php'
---

# Deliveries

## Totales y cobros en el reporte de Reparto
Mantener el cobro de la visita separado del saldo actual de la cuenta. Un cobro histórico sin conciliar es desconocido y un fiado confirmado tiene cero. En cada grupo contar las cargas y devoluciones una vez por reparto, y el saldo vigente una vez por cliente. El reporte con saldos necesita acceso a Cuenta Corriente y descarga privada.
