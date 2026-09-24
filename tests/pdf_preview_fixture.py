"""Small, entirely fictional PDF for browser preview tests; no filesystem writes."""
def preview_pdf():
    stream=b'BT /F1 20 Tf 50 760 Td (o8 Test invoice) Tj 0 -45 Td /F1 12 Tf (Invoice: KI-42) Tj 0 -25 Td (Net: 100.00 EUR) Tj 0 -25 Td (VAT: 19.00 EUR) Tj 0 -25 Td (Gross: 119.00 EUR) Tj ET'
    objects=[b'<< /Type /Catalog /Pages 2 0 R >>',b'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
             b'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
             b'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
             b'<< /Length '+str(len(stream)).encode()+b' >>\nstream\n'+stream+b'\nendstream']
    pdf=b'%PDF-1.4\n';offsets=[0]
    for index,obj in enumerate(objects,1):
        offsets.append(len(pdf));pdf+=f'{index} 0 obj\n'.encode()+obj+b'\nendobj\n'
    xref=len(pdf);pdf+=b'xref\n0 6\n0000000000 65535 f \n'
    for offset in offsets[1:]:pdf+=f'{offset:010d} 00000 n \n'.encode()
    return pdf+f'trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n{xref}\n%%EOF\n'.encode()
