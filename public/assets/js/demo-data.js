// Ausschließlich erfundene Beispieldaten für die Abnahme von Meilenstein 1.
export function createEmptyDemoData() {
    const data = createDemoData();
    data.documents = []; data.folders = []; data.links = []; data.tags = [];
    data.users = [{id:1,name:'Mandanten-Admin (Demo)',email:'admin@example.invalid',role:'admin',active:true},
        {id:2,name:'Mandanten-User (Demo)',email:'user@example.invalid',role:'user',active:true}];
    return data;
}
export function createDemoData() {
    const data = {
        version: 2,
        users: [{id:1, name:'Alex Muster', email:'alex@example.invalid', role:'admin', active:true}, {id:2, name:'Kim Beispiel', email:'kim@example.invalid', role:'user', active:true}],
        folders: [
            { id: 1, name: 'Finanzen', parentId: null, color: '#39756d' },
            { id: 2, name: 'Rechnungen 2026', parentId: 1, color: '#39756d' },
            { id: 3, name: 'Steuerunterlagen', parentId: 1, color: '#946d36' },
            { id: 4, name: 'Haus & Wohnen', parentId: null, color: '#567d9b' },
            { id: 5, name: 'Verträge', parentId: null, color: '#8972a9' },
        ],
        tags: ['Rechnung', 'Vertrag', 'Versicherung', 'Wohnen', 'Steuer', 'Privat', 'Büro', 'Energie', 'Fahrzeug', 'Handwerker', 'Gesundheit', 'Reise', ...Array.from({ length: 200 }, (_, index) => `Projekt ${String(index + 1).padStart(3, '0')}`)],
        documents: [
            { id: 101, title: 'Strom · Jahresabrechnung 2026', sender: 'Nordlicht Energie', date: '2026-09-14', type: 'Rechnung', amountCents: 128450, tags: ['Rechnung', 'Wohnen'], source: 'E-Mail', filename: 'jahresabrechnung-2026.pdf', memo: 'Zählerstand und Abschläge sind in der Abrechnung enthalten.', reference: 'NE-2026-0842', deletedAt: null },
            { id: 102, title: 'Wohngebäudeversicherung', sender: 'Hanseatische Versicherung', date: '2026-09-12', type: 'Vertrag', amountCents: null, tags: ['Versicherung', 'Vertrag', 'Wohnen'], source: 'WebDAV', filename: 'versicherung-wohngebaeude.pdf', memo: 'Versicherungszeitraum: September 2026 bis August 2027.', reference: 'HV-20394', deletedAt: null },
            { id: 103, title: 'Arbeitszimmer · Ausstattung', sender: 'Studio Kontur', date: '2026-09-10', type: 'Rechnung', amountCents: 64900, tags: ['Rechnung', 'Steuer'], source: 'Upload', filename: 'rechnung-kontur.pdf', memo: '', reference: 'SK-2026-219', deletedAt: null },
            { id: 104, title: 'Internet · September', sender: 'Fibernetz', date: '2026-09-08', type: 'Rechnung', amountCents: 4990, tags: ['Rechnung', 'Wohnen'], source: 'E-Mail', filename: 'fibernetz-september.pdf', memo: '', reference: 'FN-0926', deletedAt: null },
            { id: 105, title: 'Wartung der Heizungsanlage', sender: 'Wärme & Werk', date: '2026-09-05', type: 'Rechnung', amountCents: 28750, tags: ['Rechnung', 'Wohnen', 'Steuer'], source: 'Scan / Inbound', filename: 'wartung-heizung.pdf', memo: 'Jährliche Wartung durchgeführt.', reference: 'WW-4521', deletedAt: null },
            { id: 106, title: 'Mietvertrag · Stellplatz', sender: 'Hausverwaltung Lindenhof', date: '2026-09-01', type: 'Vertrag', amountCents: null, tags: ['Vertrag', 'Privat'], source: 'Scan / Inbound', filename: 'mietvertrag-stellplatz.pdf', memo: '', reference: 'HL-71', deletedAt: null },
            { id: 107, title: 'Bescheinigung über Spende', sender: 'Stadtgarten e. V.', date: '2026-08-29', type: 'Bescheinigung', amountCents: 10000, tags: ['Steuer', 'Privat'], source: 'E-Mail', filename: 'spendenbescheinigung.pdf', memo: '', reference: 'SG-2026-18', deletedAt: null },
            { id: 108, title: 'Reiseunterlagen · Herbst', sender: 'Bergzeit Reisen', date: '2026-08-25', type: 'Dokument', amountCents: null, tags: ['Privat'], source: 'Upload', filename: 'reiseunterlagen.pdf', memo: 'Noch keinem Ordner zugeordnet.', reference: 'BZ-8712', deletedAt: null },
        ],
        links: [
            { documentId: 101, folderId: 2 }, { documentId: 101, folderId: 4 },
            { documentId: 102, folderId: 4 }, { documentId: 102, folderId: 5 },
            { documentId: 103, folderId: 2 }, { documentId: 103, folderId: 3 },
            { documentId: 104, folderId: 2 },
            { documentId: 105, folderId: 2 }, { documentId: 105, folderId: 3 }, { documentId: 105, folderId: 4 },
            { documentId: 106, folderId: 5 }, { documentId: 107, folderId: 3 },
        ],
    };
    for (const doc of data.documents) {
        doc.expired = false;
        doc.searchable = true;
        doc.ownerId = 1;
        // Fiktiver Aufnahmezeitpunkt; unabhängig vom Belegdatum.
        doc.createdAt = `2026-09-16T10:${String(doc.id - 101).padStart(2,'0')}:00Z`;
        doc.inInbox = [107, 108].includes(doc.id);
        if (doc.type === 'Rechnung') {
            // Rein fiktive Summen der Beispieldokumente.
            const net = Math.round(doc.amountCents / 1.19);
            doc.invoice = { sender: doc.sender, number: doc.reference, date: doc.date, currency: 'EUR', mode: 'totals', net: (net / 100).toFixed(2), taxes: [{ rate: '19', amount: ((doc.amountCents - net) / 100).toFixed(2) }], items: [] };
        }
    }
    return data;
}
