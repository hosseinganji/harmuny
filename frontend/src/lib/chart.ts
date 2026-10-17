/** One column of a BarChart. */
export interface Bar {
  label: string
  /** Longer label for the tooltip and the table view. */
  title: string
  value: number
  detail?: string
}
