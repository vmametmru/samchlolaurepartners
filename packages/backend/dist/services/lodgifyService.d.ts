import { LodgifyProperty, LodgifyAvailabilityDay, LodgifyRate } from '@samchlolaurepartners/shared';
export declare function getProperties(): Promise<LodgifyProperty[]>;
export declare function getProperty(propertyId: number): Promise<LodgifyProperty>;
export declare function getAvailability(propertyId: number, from: string, to: string): Promise<LodgifyAvailabilityDay[]>;
export declare function getRates(propertyId: number, from: string, to: string, guests: number): Promise<LodgifyRate[]>;
//# sourceMappingURL=lodgifyService.d.ts.map