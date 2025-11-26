declare module 'vue-select' {
  import { DefineComponent } from 'vue';
  
  interface VueSelectProps<T = any> {
    /** Options displayed in the dropdown */
    options?: T[];
    /** Property name used for the label (default: "label") */
    label?: keyof T;
    /** Function that reduces an option to the model value */
    reduce?: (item: T) => any;
    /** Placeholder text */
    placeholder?: string;
    // Add other props you use
    modelValue?: any;
    multiple?: boolean;
    disabled?: boolean;
    searchable?: boolean;
    clearable?: boolean;
  }

  const VueSelect: DefineComponent<
    VueSelectProps<any>,
    {}, 
    {}, 
    any, 
    {}, 
    {}, 
    {},
    {
      // Define the slots here
      'option': (props: any) => any;
      'selected-option': (props: any) => any;
    }
  >;
  
  export default VueSelect;
}