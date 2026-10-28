/** The modal title for a person's waiver (legacy waiverTitle): "Jane's Waiver", "James' Waiver". */
export function waiverTitle(personName: string): string {
    const name = personName.trim() || 'Waiver';

    return name.endsWith('s') || name.endsWith('S') ? `${name}' Waiver` : `${name}'s Waiver`;
}
