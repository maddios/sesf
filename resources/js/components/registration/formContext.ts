import { inject, provide, type InjectionKey } from 'vue';
import type { FormValues } from '../../composables/registration/form';

/**
 * The registration form is shared with every section through provide/inject: sections edit the one reactive form
 * the page owns, without props that are mutated from the child.
 */
const FORM_KEY: InjectionKey<FormValues> = Symbol('registration-form');

export function provideFormValues(values: FormValues): void {
  provide(FORM_KEY, values);
}

export function useFormValues(): FormValues {
  const values = inject(FORM_KEY);
  if (values === undefined) {
    throw new Error('Registration sections must be rendered inside RegistrationPage');
  }
  return values;
}
